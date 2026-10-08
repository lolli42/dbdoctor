<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor;

use Lolli\Dbdoctor\DependencyInjection\HealthCheckPass;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    $healthCheckTagName = 'lolli.dbdoctor.health';
    $containerBuilder->registerForAutoconfiguration(HealthCheckInterface::class)->addTag($healthCheckTagName);
    $containerBuilder->addCompilerPass(new HealthCheckPass($healthCheckTagName));
};
