<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Unit\DependencyInjection;

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
use Lolli\Dbdoctor\DependencyInjection\HealthCheckPass;
use Lolli\Dbdoctor\HealthFactory\HealthFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class HealthCheckPassTest extends UnitTestCase
{
    #[Test]
    public function processDoesNothingWithoutHealthFactory(): void
    {
        $container = new ContainerBuilder();
        $container->register('check.a')->addTag('lolli.dbdoctor.health', ['identifier' => 'a']);

        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);

        self::assertFalse($container->hasDefinition(HealthFactory::class));
    }

    #[Test]
    public function checksAreOrderedByBeforeAndAfter(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        // Registered in a different order to verify ordering is done by before / after only
        $container->register('check.c')->addTag('lolli.dbdoctor.health', ['identifier' => 'c', 'after' => 'b']);
        $container->register('check.a')->addTag('lolli.dbdoctor.health', ['identifier' => 'a']);
        $container->register('check.d')->addTag('lolli.dbdoctor.health', ['identifier' => 'd', 'after' => 'c']);
        $container->register('check.b')->addTag('lolli.dbdoctor.health', ['identifier' => 'b', 'after' => 'a', 'before' => 'c']);

        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);

        self::assertSame(['check.a', 'check.b', 'check.c', 'check.d'], $this->getOrderedServiceIds($container));
    }

    #[Test]
    public function serviceWithMultipleTagsIsAddedMultipleTimes(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')
            ->addTag('lolli.dbdoctor.health', ['identifier' => 'a-first'])
            ->addTag('lolli.dbdoctor.health', ['identifier' => 'a-second', 'after' => 'b']);
        $container->register('check.b')->addTag('lolli.dbdoctor.health', ['identifier' => 'b', 'after' => 'a-first']);

        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);

        self::assertSame(['check.a', 'check.b', 'check.a'], $this->getOrderedServiceIds($container));
    }

    #[Test]
    public function serviceWithAutoconfiguredTagOnlyUsesServiceIdAsIdentifier(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')->addTag('lolli.dbdoctor.health');

        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);

        $argument = $container->getDefinition(HealthFactory::class)->getArgument('$healthChecks');
        self::assertInstanceOf(IteratorArgument::class, $argument);
        self::assertSame(['check.a'], array_keys($argument->getValues()));
    }

    #[Test]
    public function autoconfiguredTagIsIgnoredIfConfiguredTagExists(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')
            ->addTag('lolli.dbdoctor.health')
            ->addTag('lolli.dbdoctor.health', ['identifier' => 'a']);

        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);

        self::assertSame(['check.a'], $this->getOrderedServiceIds($container));
    }

    #[Test]
    public function duplicateIdentifierThrowsException(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')->addTag('lolli.dbdoctor.health', ['identifier' => 'a']);
        $container->register('check.b')->addTag('lolli.dbdoctor.health', ['identifier' => 'a']);

        $this->expectException(\LogicException::class);
        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);
    }

    #[Test]
    public function multipleTagsWithoutIdentifierThrowsException(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')
            ->addTag('lolli.dbdoctor.health', ['identifier' => 'a'])
            ->addTag('lolli.dbdoctor.health', ['after' => 'a']);

        $this->expectException(\LogicException::class);
        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);
    }

    #[Test]
    public function emptyIdentifierThrowsException(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')->addTag('lolli.dbdoctor.health', ['identifier' => ' ']);

        $this->expectException(\LogicException::class);
        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);
    }

    #[Test]
    public function nonStringIdentifierThrowsException(): void
    {
        $container = new ContainerBuilder();
        $container->register(HealthFactory::class);
        $container->register('check.a')->addTag('lolli.dbdoctor.health', ['identifier' => 42]);

        $this->expectException(\LogicException::class);
        (new HealthCheckPass('lolli.dbdoctor.health'))->process($container);
    }

    /**
     * @return list<string>
     */
    private function getOrderedServiceIds(ContainerBuilder $container): array
    {
        $argument = $container->getDefinition(HealthFactory::class)->getArgument('$healthChecks');
        self::assertInstanceOf(IteratorArgument::class, $argument);
        return array_values(array_map(
            static fn(Reference $reference): string => (string)$reference,
            $argument->getValues(),
        ));
    }
}
