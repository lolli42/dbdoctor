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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Records created in a workspace (t3ver_state=1) are no overlays, they have t3ver_oid=0 until they are
 * published. A new record with t3ver_oid!=0 mixes both. WorkspaceRestriction selects t3ver_oid=0 or move
 * pointers only: Backend lists and the workspace preview never show it. The workspace module lists it as
 * change of the record its t3ver_oid points to, and publishing does exactly that: The module sends the
 * publish command for "t3ver_oid ?: uid", DataHandlerHook->version_swap() handles it as overlay of that
 * live record, copies its content into the live record and deletes the new record (13.4, 14.3).
 * The content of the live record is lost.
 *
 * t3ver_oid is set to 0: The record becomes a regular new record of its workspace, shown in the preview
 * and published as new live record, the other live record is kept. New records whose t3ver_oid points to
 * a missing, soft-deleted or not live record are removed by the earlier check WorkspacesOverlayLiveRecordInvalid.
 */
final readonly class WorkspacesNewRecordT3verOidNotZero extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for new workspace records with t3ver_oid!=0',
            [ActionTag::UpdateFields],
            [
                'Records created in a workspace (t3ver_state=1) have t3ver_oid=0 until they are published.',
                'With t3ver_oid!=0, the workspace preview does not show them, and publishing overwrites the',
                'live record t3ver_oid points to with their content. This check sets t3ver_oid=0: They become',
                'regular new records of their workspace, published as new live records.',
            ],
        );
    }

    public function detect(PreparedStatements $statements): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Soft-deleted records are handled as well.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_oid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(VersionState::NEW_PLACEHOLDER->value, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], ['t3ver_oid' => ['value' => 0, 'type' => Connection::PARAM_INT]])],
                    new Reason('new-record-with-oid', 'New workspace record with t3ver_oid "' . $row['t3ver_oid'] . '"', ['liveUid' => (int)$row['t3ver_oid']]),
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
