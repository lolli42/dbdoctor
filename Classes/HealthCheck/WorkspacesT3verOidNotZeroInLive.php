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
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Live records (t3ver_wsid=0) with t3ver_oid>0 are overlays that do not belong to any workspace.
 * Core never lists them: WorkspaceRestriction, the frontend enable fields of PageRepository and Extbase
 * all select t3ver_oid=0 or move pointers. Only lookups by uid without these constraints return them,
 * for instance a relation pointing to such a uid, or extension queries with default restrictions only,
 * which return the overlays of all workspaces as well.
 * The core command "cleanup:versions --action invalid_workspace" creates them by setting t3ver_wsid=0
 * for overlays of deleted workspaces. Publishing a record turns the former live record into such a row
 * for a moment and deletes it right after, an interrupted publish leaves it behind.
 * They are removed, as "cleanup:versions --action versions_in_live" does.
 * Live records with t3ver_state!=0 are left to WorkspacesT3verStateNotZeroInLive, which runs before and
 * sets t3ver_state=0 while keeping t3ver_oid: Those are found here when that check has been executed.
 */
final readonly class WorkspacesT3verOidNotZeroInLive extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for records with t3ver_wsid=0 and t3ver_oid!=0',
            [ActionTag::WorkspaceRemove],
            [
                'Live records (t3ver_wsid=0) with t3ver_oid!=0 are workspace overlays that do not',
                'belong to any workspace. Core never lists them in backend or frontend. The core command',
                '"cleanup:versions --action invalid_workspace" creates them, an interrupted publish leaves',
                'them behind. This check removes them.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Soft-deleted records are dead rows as well, all restrictions are removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_oid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, $tableName, (int)$row['uid'])],
                    new Reason('overlay-in-live', 'Overlay of record "' . $row['t3ver_oid'] . '" without workspace', ['liveUid' => (int)$row['t3ver_oid']]),
                );
            }
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['t3ver_oid'];
    }
}
