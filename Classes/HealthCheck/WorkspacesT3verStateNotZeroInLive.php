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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Live records should never have t3ver_state!=0. Find and change those,
 * handling depends on current FE behavior.
 */
final readonly class WorkspacesT3verStateNotZeroInLive extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records with t3ver_wsid=0 and t3ver_state!=0');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE, self::TAG_SOFT_DELETE, self::TAG_UPDATE);
        $io->text([
            'There should be no t3ver_state non-zero (0) records in live.',
            'If this check finds records, ABORT NOW and run these upgrades wizards:',
            'WorkspaceVersionRecordsMigration (TYPO3 v10 & v11),',
            'WorkspaceNewPlaceholderRemovalMigration (TYPO3 v11 & v12),',
            'WorkspaceMovePlaceholderRemovalMigration (TYPO3 v11 & v12).',
            'If there are still affected records, this check will remove, soft-delete or update them,',
            'depending on their specific t3ver_state value: Records typically shown in FE are kept,',
            'others are deleted or soft-deleted.',
        ]);
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
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
            while ($tableRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $tableRow */
                $uid = (int)$tableRow['uid'];
                if ($isTableDeleteAware && ((int)$tableRow[$deleteField] === 1)) {
                    // If row is soft-deleted already, we now fully remove it.
                    $change = new RecordChange(RecordChangeType::Delete, $tableName, $uid);
                } elseif ((int)$tableRow['t3ver_state'] <= -1) {
                    // t3ver_state < 0 lead to exceptions in the BE page module, but are
                    // SHOWN in FE - we update to t3ver_state=0 and keep them.
                    $change = new RecordChange(RecordChangeType::Update, $tableName, $uid, [
                        't3ver_state' => [
                            'value' => 0,
                            'type' => Connection::PARAM_INT,
                        ],
                    ]);
                } elseif ($isTableDeleteAware) {
                    // t3ver_state > 0 are never shown in FE and may lead to exceptions in the BE page module.
                    // If the table is soft-delete aware, we set those records deleted=1 and t3ver_state=0
                    $change = new RecordChange(RecordChangeType::Update, $tableName, $uid, [
                        $deleteField => [
                            'value' => 1,
                            'type' => Connection::PARAM_INT,
                        ],
                        't3ver_state' => [
                            'value' => 0,
                            'type' => Connection::PARAM_INT,
                        ],
                    ]);
                } else {
                    // t3ver_state > 0 are never shown in FE and may lead to exceptions in the BE page module.
                    // The table is not soft-delete aware, we remove the record.
                    $change = new RecordChange(RecordChangeType::Delete, $tableName, $uid);
                }
                $findings[] = new Finding(
                    new RecordKey($tableName, $uid),
                    (int)$tableRow['pid'],
                    [$change],
                );
            }
        }
        return $findings;
    }
}
