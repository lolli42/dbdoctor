<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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

use Psr\Container\ContainerInterface;

class HealthFactory implements HealthFactoryInterface
{
    /**
     * @var string[]
     */
    private array $healthClasses = [
        TcaTablesWorkspaceRecordsDangling::class,
        PagesBrokenTree::class,
        PagesTranslatedLanguageParentMissing::class,
        PagesTranslatedLanguageParentDeleted::class,
        PagesTranslatedLanguageParentDifferentPid::class,
        // This one is relatively early since it is rather safe and prevents loops on checks below.
        TcaTablesTranslatedParentInvalidPointer::class,
        // @todo: Next one is skipped in v12 and can be dropped when v11 compat is removed from extension.
        SysFileReferenceInvalidTableLocal::class,
        SysFileReferenceLocalizedParentExists::class,
        SysFileReferenceLocalizedParentDeleted::class,
        SysFileReferenceLocalizedFieldSync::class,
        SysFileReferenceDangling::class,
        SysFileReferenceInvalidPid::class,
        TcaTablesPidMissing::class,
        SysFileReferenceInvalidFieldname::class,
        TcaTablesPidDeleted::class,
        TcaTablesTranslatedLanguageParentMissing::class,
        // Check sys_file_reference pointing to not existing records *again*:
        // TcaTablesTranslatedLanguageParentMissing may have deleted some.
        SysFileReferenceDangling::class,
        TcaTablesTranslatedLanguageParentDeleted::class,
        TcaTablesTranslatedLanguageParentDifferentPid::class,
        // TcaTablesInvalidLanguageParent::class,
        InlineForeignFieldChildrenParentMissing::class,
        InlineForeignFieldChildrenParentDeleted::class,
        // @todo: Maybe that's not a good position when we start scanning for records translated more than once (issue #9)?
        InlineForeignFieldChildrenParentLanguageDifferent::class,
    ];

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getNext(): iterable
    {
        foreach ($this->healthClasses as $class) {
            /** @var object $instance */
            $instance = $this->container->get($class);
            if (!$instance instanceof HealthInterface) {
                throw new \InvalidArgumentException(get_class($instance) . 'does not implement HealthInterface');
            }
            yield $instance;
        }
    }
}
