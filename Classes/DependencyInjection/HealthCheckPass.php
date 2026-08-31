<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DependencyInjection;

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
                ];
            }
        }

        $healthChecks = (new DependencyOrderingService())->orderByDependencies($healthChecks);

        $references = array_map(
            static fn(array $healthCheck): Reference => new Reference($healthCheck['id']),
            $healthChecks,
        );

        $container
            ->findDefinition(HealthFactory::class)
            ->setArgument('$healthChecks', new IteratorArgument($references));
    }

    /**
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private function resolveTags(array $tags): array
    {
        $configuredTags = array_values(
            array_filter(
                $tags,
                static fn(array $tag): bool => array_key_exists('identifier', $tag)
                    || array_key_exists('before', $tag)
                    || array_key_exists('after', $tag),
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
