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

/**
 * TYPO3 v11 migrated workspace related record content having t3ver_state=-1 into
 * t3ver_state=1 records and removed them.
 * This check finds leftovers and removes them from the database.
 */
final readonly class WorkspacesT3verStateMinusOne extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for records with t3ver_state=-1',
            [self::TAG_WORKSPACE_REMOVE],
            [
                'The workspace related field state t3ver_state=-1 has been removed with TYPO3 v11.',
                'Until TYPO3 v11, they were paired with a t3ver_state=-1 record. A core upgrade',
                'wizard migrates affected records. This check removes left over records having t3ver_state=-1.',
                'If this check finds records, it may indicate the upgrade wizard "WorkspaceNewPlaceholderRemovalMigration"',
                'has not been run. ABORT NOW and run the wizard if it is still available in your TYPO3 version.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // We want to find "deleted=1" records, so obviously especially the "delete" restriction is removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid', 't3ver_state')->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_state', -1),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, $tableName, (int)$row['uid'])],
                );
            }
        }
        return $findings;
    }
}
