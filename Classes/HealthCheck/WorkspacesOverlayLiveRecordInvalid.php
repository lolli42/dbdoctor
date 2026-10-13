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
 * Workspace overlays (t3ver_wsid>0, t3ver_oid>0) point to their live record with t3ver_oid. This holds for
 * changed records, delete placeholders and move pointers. Records created in a workspace have t3ver_oid=0.
 * Core finds overlays only through their live record: frontend, page module and workspace module never show
 * an overlay whose live record does not exist, is soft-deleted, or is no live record itself, and publishing
 * skips it. Core discards all overlays of a live record when deleting it (DataHandler->deleteEl()), so such
 * overlays are leftovers of records deleted with low level queries, for instance by imports or other checks.
 * They are removed, workspace records are not soft-delete aware. Only the overlay row is removed, unlike core
 * discard(): Workspace translations or MM rows of the overlay are left to other checks.
 */
final readonly class WorkspacesOverlayLiveRecordInvalid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for workspace overlays without valid live record',
            [ActionTag::WorkspaceRemove],
            [
                'Workspace overlays point to their live record in field t3ver_oid. Core never shows an',
                'overlay whose live record does not exist, is soft-deleted, or is no live record itself, and',
                'can not publish it: When deleting a live record, core discards all its overlays. This check',
                'removes such overlays. Other records of the overlay, like workspace translations or MM rows,',
                'are left to other checks.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $deletedField = $this->tcaHelper->getDeletedField($tableName);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $expr = $queryBuilder->expr();
            $queryBuilder->select('overlay.uid', 'overlay.pid', 'overlay.t3ver_oid', 'live.uid AS live_uid', 'live.t3ver_wsid AS live_wsid', 'live.t3ver_oid AS live_oid')
                ->from($tableName, 'overlay')
                ->leftJoin(
                    'overlay',
                    $tableName,
                    'live',
                    $expr->eq('live.uid', $queryBuilder->quoteIdentifier('overlay.t3ver_oid'))
                )
                ->where(
                    $expr->gt('overlay.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $expr->gt('overlay.t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // New records with t3ver_oid!=0 are not overlays, see https://github.com/lolli42/dbdoctor/issues/283
                    $expr->neq('overlay.t3ver_state', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                )
                ->orderBy('overlay.uid');
            $invalidLiveConstraints = [
                $expr->isNull('live.uid'),
                $expr->neq('live.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->neq('live.t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            ];
            if ($deletedField !== null) {
                $queryBuilder->addSelect('live.' . $deletedField . ' AS live_deleted');
                $invalidLiveConstraints[] = $expr->eq('live.' . $deletedField, $queryBuilder->createNamedParameter(1, Connection::PARAM_INT));
            }
            $result = $queryBuilder->andWhere($expr->or(...$invalidLiveConstraints))->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $row */
                $liveUid = (int)$row['t3ver_oid'];
                if ($row['live_uid'] === null) {
                    $reason = new Reason('live-missing', 'Live record "' . $liveUid . '" does not exist', ['liveUid' => $liveUid]);
                } elseif ((int)$row['live_wsid'] !== 0 || (int)$row['live_oid'] !== 0) {
                    $reason = new Reason('live-not-live', 'Record "' . $liveUid . '" is no live record', ['liveUid' => $liveUid]);
                } else {
                    $reason = new Reason('live-deleted', 'Live record "' . $liveUid . '" is soft-deleted', ['liveUid' => $liveUid]);
                }
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, $tableName, (int)$row['uid'])],
                    $reason,
                );
            }
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['t3ver_oid', 't3ver_state'];
    }
}
