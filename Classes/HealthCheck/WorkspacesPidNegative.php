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
 * TYPO3 v10 migrated workspace related records away from pid=-1.
 * This check finds leftovers and removes them from the database.
 */
final readonly class WorkspacesPidNegative extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for records with negative pid',
            [self::TAG_REMOVE],
            [
                'Records must have a pid equal or greater than zero (0).',
                'Until TYPO3 v10, workspace records where placed on pid=-1. This check removes leftovers.',
                'If this check finds records, it may indicate the upgrade wizard "WorkspaceVersionRecordsMigration"',
                'has not been run. ABORT NOW and run the wizard, it is included in TYPO3 core v10 and v11.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // We want to find "deleted=1" records, so obviously especially the "delete" restriction is removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->lt('pid', 0),
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
