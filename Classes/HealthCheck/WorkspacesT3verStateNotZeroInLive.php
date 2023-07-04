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

use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Live records should never have t3ver_state!=0. Find and change those,
 * handling depends on current FE behavior.
 */
final class WorkspacesT3verStateNotZeroInLive extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records with t3ver_wsid=0 and t3ver_state!=0');
        $this->outputTags($io, self::TAG_REMOVE, self::TAG_SOFT_DELETE, self::TAG_UPDATE);
        $io->text([
            'There should be not t3ver_state non-zero (0) records in live.',
            'If this check finds records, ABORT NOW and run these upgrades wizards:',
            'WorkspaceVersionRecordsMigration (TYPO3 v10 & v11),',
            'WorkspaceNewPlaceholderRemovalMigration (TYPO3 11 & v12),',
            'WorkspaceMovePlaceholderRemovalMigration (TYPO3 v11 & v12).',
            'If there are still affected records, this check will remove, soft-delete or update them,',
            'depending on their specific t3ver_state value: Records typically shown in FE are kept,',
            'others are deleted or soft-deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $deleteField = $this->tcaHelper->getDeletedField($tableName);
            $isTableDeleteAware = !empty($deleteField);

            $selectFields = [
                'uid',
                'pid',
                't3ver_wsid',
                't3ver_state',
            ];
            if ($isTableDeleteAware) {
                $selectFields[] = $deleteField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // We want to find "deleted=1" records, so obviously especially the "delete" restriction is removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', 0),
                    $queryBuilder->expr()->neq('t3ver_state', 0),
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
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $tableRows) {
            if ($simulate) {
                $io->note('[SIMULATE] Handle records on table: ' . $tableName);
            } else {
                $io->note('Handle records on table: ' . $tableName);
            }
            $updateCount = 0;
            $deleteCount = 0;

            $deleteField = $this->tcaHelper->getDeletedField($tableName);
            $isTableDeleteAware = !empty($deleteField);

            foreach ($tableRows as $tableRow) {
                if ($isTableDeleteAware && ((int)$tableRow[$deleteField] === 1)) {
                    // If row is soft-deleted already, we now fully remove it.
                    $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$tableRow['uid']);
                    $deleteCount ++;
                } elseif ((int)$tableRow['t3ver_state'] <= -1) {
                    // t3ver_state < 0 lead to exceptions in BE page module in v12, but are
                    // SHOWN in FE - we update to t3ver_state=0 and keep them.
                    $updateFields = [
                        't3ver_state' => [
                            'value' => 0,
                            'type' => \PDO::PARAM_INT,
                        ],
                    ];
                    $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$tableRow['uid'], $updateFields);
                    $updateCount ++;
                } elseif ($isTableDeleteAware) {
                    // t3ver_state > 0 are never shown in FE and may lead to exceptions in BE page module in v12.
                    // If the table is soft-delete aware, we set those records deleted=1 and t3ver_state=0
                    $updateFields = [
                        $deleteField => [
                            'value' => 1,
                            'type' => \PDO::PARAM_INT,
                        ],
                        't3ver_state' => [
                            'value' => 0,
                            'type' => \PDO::PARAM_INT,
                        ],
                    ];
                    $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$tableRow['uid'], $updateFields);
                    $updateCount ++;
                } else {
                    // t3ver_state > 0 are never shown in FE and may lead to exceptions in BE page module in v12.
                    // The table is not soft-delete aware, we remove the record.
                    $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$tableRow['uid']);
                    $deleteCount ++;
                }
            }

            if ($simulate) {
                if ($updateCount > 0) {
                    $io->note('[SIMULATE] Update "' . $updateCount . '" records from "' . $tableName . '" table');
                }
                if ($deleteCount > 0) {
                    $io->note('[SIMULATE] Delete "' . $deleteCount . '" records from "' . $tableName . '" table');
                }
            } else {
                if ($updateCount > 0) {
                    $io->warning('Updated "' . $updateCount . '" records from "' . $tableName . '" table');
                }
                if ($deleteCount > 0) {
                    $io->warning('Deleted "' . $deleteCount . '" records from "' . $tableName . '" table');
                }
            }
        }
    }
}
