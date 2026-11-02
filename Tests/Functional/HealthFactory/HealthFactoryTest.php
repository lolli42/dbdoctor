<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\HealthFactory;

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
use Lolli\Dbdoctor\Event\ModifyHealthClassListEvent;
use Lolli\Dbdoctor\HealthCheck\TcaTablesPidMissing;
use Lolli\Dbdoctor\HealthCheck\WorkspacesNotLoadedRecordsDangling;
use Lolli\Dbdoctor\HealthFactory\HealthFactoryInterface;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

class HealthFactoryTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    /**
     * @return list<class-string>
     */
    private function getClassesOfChain(): array
    {
        $classes = [];
        foreach ($this->get(HealthFactoryInterface::class)->getNext() as $healthCheck) {
            $classes[] = $healthCheck::class;
        }
        return $classes;
    }

    #[Test]
    public function getNextReturnsChainStartingWithFirstCheck(): void
    {
        $classes = $this->getClassesOfChain();
        self::assertSame(WorkspacesNotLoadedRecordsDangling::class, $classes[0]);
        self::assertContains(TcaTablesPidMissing::class, $classes);
    }

    #[Test]
    public function modifyHealthClassListEventRemovesCheck(): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'health-factory-test-remove',
            static function (ModifyHealthClassListEvent $event): void {
                $event->healthClasses = array_values(array_diff($event->healthClasses, [TcaTablesPidMissing::class]));
            }
        );
        $container->get(ListenerProvider::class)->addListener(ModifyHealthClassListEvent::class, 'health-factory-test-remove');
        $classes = $this->getClassesOfChain();
        self::assertNotContains(TcaTablesPidMissing::class, $classes);
        self::assertSame(WorkspacesNotLoadedRecordsDangling::class, $classes[0]);
    }

    #[Test]
    public function modifyHealthClassListEventReordersChecks(): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'health-factory-test-reorder',
            static function (ModifyHealthClassListEvent $event): void {
                $event->healthClasses = array_values(array_diff($event->healthClasses, [TcaTablesPidMissing::class]));
                array_unshift($event->healthClasses, TcaTablesPidMissing::class);
            }
        );
        $container->get(ListenerProvider::class)->addListener(ModifyHealthClassListEvent::class, 'health-factory-test-reorder');
        $classes = $this->getClassesOfChain();
        self::assertSame(TcaTablesPidMissing::class, $classes[0]);
        self::assertSame(WorkspacesNotLoadedRecordsDangling::class, $classes[1]);
        self::assertCount(1, array_keys($classes, TcaTablesPidMissing::class, true));
    }
}
