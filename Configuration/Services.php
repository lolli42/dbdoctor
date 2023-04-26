<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor;

use Lolli\Dbdoctor\Health\HealthInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\DependencyInjection\PublicServicePass;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    $containerBuilder->registerForAutoconfiguration(HealthInterface::class)->addTag('lolli.dbdoctor.health');
    $containerBuilder->addCompilerPass(new PublicServicePass('lolli.dbdoctor.health', true));
};
