<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DatabaseSchema;

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

use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

final class DatabaseSchemaChecker
{
    private SqlReader $sqlReader;
    private SchemaMigrator $schemaMigrator;

    public function __construct(
        SqlReader $sqlReader,
        SchemaMigrator $schemaMigrator
    ) {
        $this->sqlReader = $sqlReader;
        $this->schemaMigrator = $schemaMigrator;
    }

    public function hasIncompleteTablesColumnsIndexes(): bool
    {
        $databaseDifferences = $this->schemaMigrator->getSchemaDiffs(
            $this->sqlReader->getCreateTableStatementArray(
                $this->sqlReader->getTablesDefinitionString()
            )
        );
        foreach ($databaseDifferences as $schemaDiff) {
            if (!empty($schemaDiff->newTables)) {
                // Table missing
                return true;
            }
            foreach ($schemaDiff->changedTables as $changedTable) {
                if (!empty($changedTable->addedColumns)) {
                    // Column missing
                    return true;
                }
                // @todo: Core seems to have an issue with postgres and "unsigned".
                //        It mumbles about int fields not being unsigned on postgres.
                //        This needs to be fixed in core, before the below check can
                //        be activated as well.
                /*
                if (!empty($changedTable->changedColumns)) {
                    // Column has to be changed
                    return true;
                }
                */
                if (!empty($changedTable->addedIndexes)) {
                    // Index missing
                    return true;
                }
            }
        }
        return false;
    }
}
