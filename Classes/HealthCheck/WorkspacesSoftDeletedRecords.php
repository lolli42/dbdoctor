<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

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

use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Workspace records (t3ver_wsid != 0) are NOT soft-delete aware. Find
 * all deleted=1 workspace records and delete them.
 */
class WorkspacesSoftDeletedRecords extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for soft-deleted workspaces records');
        $io->text([
            '[DELETE] Records in workspaces (t3ver_wsid != 0) are not soft-delete aware since TYPO3 v11:',
            '         When "discarding" workspace changes, affected records are fully removed from the database.',
            '         This check looks for "t3ver_wsid != 0" having "deleted = 1" and deletes them from the table.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            // Check WorkspacesNotLoadedRecordsDangling that is executed before this check
            // already deleted all workspace overlay records when ext:workspaces is NOT
            // loaded. We can thus skip this one early if ext:workspaces is not loaded.
            return [];
        }
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $tableDeleteField = $this->tcaHelper->getDeletedField($tableName);
            if (empty($tableDeleteField)) {
                // Table is not soft-delete aware. Skip it.
                continue;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // We want to find "deleted=1" records, so obviously especially the "delete" restriction is removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid', $tableDeleteField)->from($tableName)
                ->where(
                    $queryBuilder->expr()->neq('t3ver_wsid', 0),
                    $queryBuilder->expr()->eq($tableDeleteField, 1)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRows[$tableName][(int)$row['uid']] = $row;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteAllRecords($io, $simulate, $affectedRecords);
    }
}
