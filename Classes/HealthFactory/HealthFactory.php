<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthFactory;

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
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class HealthFactory implements HealthFactoryInterface
{
    public function __construct(
        private iterable $healthChecks,
        private ContainerInterface $container,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getNext(): iterable
    {
        $healthChecksMap = [];
        $healthClasses = [];

        foreach ($this->healthChecks as $healthCheck) {
            $healthClasses[] = $healthCheck::class;
            $healthChecksMap[$healthCheck::class] = $healthCheck;
        }

        $event = new ModifyHealthClassListEvent($healthClasses);
        $this->eventDispatcher->dispatch($event);

        foreach ($event->healthClasses as $healthClass) {
            if (isset($healthChecksMap[$healthClass])) {
                yield $healthChecksMap[$healthClass];
                continue;
            }

            // Fall back for newly added class by the event
            $healthCheck = $this->container->get($healthClass);

            if (!$healthCheck instanceof HealthCheckInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        '%s does not implement %s',
                        $healthClass,
                        HealthCheckInterface::class,
                    ),
                );
            }

            yield $healthCheck;
        }
    }
}
