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
                ];
            }
        }

        // Disabled checks take part in ordering and are removed afterwards: They keep their
        // position in the chain, and before / after of other checks can still reference them.
        $healthChecks = array_filter(
            (new DependencyOrderingService())->orderByDependencies($healthChecks),
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
                    || array_key_exists('disabled', $tag),
            ),
        );

        if ($configuredTags !== []) {
            return $configuredTags;
        }

        return [[]];
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
