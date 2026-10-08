<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DependencyInjection;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Lolli\Dbdoctor\HealthFactory\HealthFactory;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class HealthCheckPass implements CompilerPassInterface
{
    public function __construct(
        private string $tagName,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasDefinition(HealthFactory::class)
            && !$container->hasAlias(HealthFactory::class)
        ) {
            return;
        }

        /** @var array<string, array{id: string, before: list<string>, after: list<string>, disabled: bool, disables: ?string}> $healthChecks */
        $healthChecks = [];

        foreach ($container->findTaggedServiceIds($this->tagName) as $id => $tags) {
            $tags = $this->resolveTags($tags);

            foreach ($tags as $tag) {
                $identifier = $this->resolveIdentifier($id, $tag, count($tags));

                if (isset($healthChecks[$identifier])) {
                    throw new \LogicException(
                        sprintf(
                            'Health check identifier "%s" is configured more than once.',
                            $identifier,
                        ),
                    );
                }

                $healthChecks[$identifier] = [
                    'id' => $id,
                    'before' => GeneralUtility::trimExplode(',', $tag['before'] ?? '', true),
                    'after' => GeneralUtility::trimExplode(',', $tag['after'] ?? '', true),
                    'disabled' => $this->resolveDisabled($id, $tag),
                    'disables' => $this->resolveDisables($id, $tag),
                ];
            }
        }

        $healthChecks = $this->applyReplacements($healthChecks);

        // Disabled checks take part in ordering and are removed afterwards: They keep their
        // position in the chain, and before / after of other checks can still reference them.
        try {
            $orderedHealthChecks = (new DependencyOrderingService())->orderByDependencies($healthChecks);
        } catch (\UnexpectedValueException $e) {
            // Contradicting before / after break the container build of the entire instance. Fail
            // loudly, but name the culprit: Typically a check of another extension that relies
            // on an order of dbdoctor checks that changed with a dbdoctor update.
            throw new \LogicException(
                sprintf(
                    'The "before" and "after" attributes of tag "%s" (dbdoctor health checks) contradict each'
                    . ' other. Health checks of other extensions may rely on an order of dbdoctor checks that'
                    . ' changed. Adapt their "before" and "after". %s',
                    $this->tagName,
                    $e->getMessage(),
                ),
                1791561600,
                $e
            );
        }
        $healthChecks = array_filter(
            $orderedHealthChecks,
            static fn(array $healthCheck): bool => !$healthCheck['disabled'],
        );

        $references = array_map(
            static fn(array $healthCheck): Reference => new Reference($healthCheck['id']),
            $healthChecks,
        );

        $container
            ->findDefinition(HealthFactory::class)
            ->setArgument('$healthChecks', new IteratorArgument($references));
    }

    /**
     * An enabled check can disable other checks to replace them: The disabled check stays in
     * the chain, the replacing check is put directly after it, and checks that were ordered
     * after the disabled check are ordered after the replacing check. Unknown identifiers are
     * ignored: Checks may be renamed or removed, and an exception here would break the
     * container build of the entire instance.
     *
     * @param array<string, array{id: string, before: list<string>, after: list<string>, disabled: bool, disables: ?string}> $healthChecks
     * @return array<string, array{id: string, before: list<string>, after: list<string>, disabled: bool, disables: ?string}>
     */
    private function applyReplacements(array $healthChecks): array
    {
        $disabled = [];
        $additionalBefore = [];
        $additionalAfter = [];
        foreach ($healthChecks as $replacingIdentifier => $replacingCheck) {
            $disabledIdentifier = $replacingCheck['disables'];
            if ($replacingCheck['disabled']
                || $disabledIdentifier === null
                || $disabledIdentifier === $replacingIdentifier
                || !isset($healthChecks[$disabledIdentifier])
            ) {
                continue;
            }
            $disabled[$disabledIdentifier] = true;
            $additionalAfter[$replacingIdentifier][] = $disabledIdentifier;
            foreach ($healthChecks[$disabledIdentifier]['before'] as $beforeIdentifier) {
                $additionalBefore[$replacingIdentifier][] = $beforeIdentifier;
            }
            foreach ($healthChecks as $identifier => $healthCheck) {
                if ($identifier !== $replacingIdentifier && in_array($disabledIdentifier, $healthCheck['after'], true)) {
                    $additionalAfter[$identifier][] = $replacingIdentifier;
                }
            }
        }

        $result = [];
        foreach ($healthChecks as $identifier => $healthCheck) {
            $result[$identifier] = [
                'id' => $healthCheck['id'],
                'before' => [...$healthCheck['before'], ...($additionalBefore[$identifier] ?? [])],
                'after' => [...$healthCheck['after'], ...($additionalAfter[$identifier] ?? [])],
                'disabled' => $healthCheck['disabled'] || isset($disabled[$identifier]),
                'disables' => $healthCheck['disables'],
            ];
        }
        return $result;
    }

    /**
     * @param array<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private function resolveTags(array $tags): array
    {
        $configuredTags = array_values(
            array_filter(
                $tags,
                static fn(array $tag): bool => array_key_exists('identifier', $tag)
                    || array_key_exists('before', $tag)
                    || array_key_exists('after', $tag)
                    || array_key_exists('disabled', $tag)
                    || array_key_exists('disables', $tag),
            ),
        );

        if ($configuredTags !== []) {
            return $configuredTags;
        }

        return [[]];
    }

    /**
     * A tag disables at most one check: Disabling multiple checks that are not next to each
     * other in the chain would create a cycle. Replacing multiple checks needs one tag each.
     *
     * @param array<string, mixed> $tag
     */
    private function resolveDisables(string $serviceId, array $tag): ?string
    {
        $disables = $tag['disables'] ?? null;

        if ($disables === null) {
            return null;
        }

        if (!is_string($disables) || trim($disables) === '' || str_contains($disables, ',')) {
            throw new \LogicException(
                sprintf(
                    'Health check service "%s" has an invalid "disables", it must be a single identifier.'
                    . ' Use one tag per replaced check.',
                    $serviceId,
                ),
            );
        }

        return trim($disables);
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function resolveDisabled(string $serviceId, array $tag): bool
    {
        $disabled = $tag['disabled'] ?? false;

        if (!is_bool($disabled)) {
            throw new \LogicException(
                sprintf(
                    'Health check service "%s" has an invalid "disabled", it must be a boolean.',
                    $serviceId,
                ),
            );
        }

        return $disabled;
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function resolveIdentifier(
        string $serviceId,
        array $tag,
        int $numberOfTags,
    ): string {
        $identifier = $tag['identifier'] ?? null;

        if ($identifier === null) {
            if ($numberOfTags > 1) {
                throw new \LogicException(
                    sprintf(
                        'Health check service "%s" is configured multiple times. '
                        . 'Each tag must define a unique "identifier".',
                        $serviceId,
                    ),
                );
            }

            return $serviceId;
        }

        if (!is_string($identifier) || trim($identifier) === '') {
            throw new \LogicException(
                sprintf(
                    'Health check service "%s" has an invalid "identifier".',
                    $serviceId,
                ),
            );
        }

        return trim($identifier);
    }
}
