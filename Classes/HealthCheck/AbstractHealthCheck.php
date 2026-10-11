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
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Base of the health checks of this extension: Services and helpers for detection.
 *
 * A check implements HealthCheckInterface detect(): It returns findings, each with the changes
 * fixing it. HealthCheckRunner shows, simulates and executes them.
 *
 * Health checks are stateless: State of a single run is in HealthCheckRun. Services
 * are set by inject*() methods instead of a constructor, to keep constructors of checks free
 * for their own services. Checks extending this class must be readonly classes, too.
 */
abstract readonly class AbstractHealthCheck
{
    // Used in IO when a check is fully disabled, for instance due to TYPO3 version
    public const TAG_DISABLED = 'disabled';
    // Used in IO when a check is considered risky, for instance when it may change FE output
    public const TAG_RISKY = 'risky';
    // Used in IO when a check may DELETE records
    public const TAG_REMOVE = 'remove';
    // Used in IO when a check may "deleted=1" records
    public const TAG_SOFT_DELETE = 'soft-delete';
    // Used in IO when a check may DELETE workspace records
    public const TAG_WORKSPACE_REMOVE = 'workspace-remove';
    // Used in IO when a check may UPDATE single fields of records
    public const TAG_UPDATE = 'update-fields';
    // Used in IO when a check may INSERT missing records
    public const TAG_INSERT = 'insert';

    protected ConnectionPool $connectionPool;
    protected TcaHelper $tcaHelper;
    protected RecordsHelper $recordsHelper;
    protected TableHelper $tableHelper;

    final public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    final public function injectTcaHelper(TcaHelper $tcaHelper): void
    {
        $this->tcaHelper = $tcaHelper;
    }

    final public function injectRecordsHelper(RecordsHelper $recordsHelper): void
    {
        $this->recordsHelper = $recordsHelper;
    }

    final public function injectTableHelper(TableHelper $tableHelper): void
    {
        $this->tableHelper = $tableHelper;
    }

    /**
     * No additional ctrl fields in record details by default.
     *
     * @return list<string>
     */
    public function getDetailCtrlFields(string $tableName): array
    {
        return [];
    }

    /**
     * No additional database fields in record details by default.
     *
     * @return list<string>
     */
    public function getDetailDbFields(string $tableName): array
    {
        return [];
    }

    /**
     * DELETE or UPDATE a record, depending on TCA and the record:
     * * If the table is not delete-aware, the record is DELETED
     * * If the table is delete-aware and the record is live (t3ver_wsid = 0), the record is UPDATED with soft-delete=1
     * * If the record is workspace-aware and the record is within a workspace (t3ver_wsid > 0), the record is DELETED
     *
     * @param array<string, int|string> $row Record with "uid", and "t3ver_wsid" for workspace aware tables
     */
    final protected function softOrHardDeleteChange(string $tableName, array $row): RecordChange
    {
        $deleteField = $this->tcaHelper->getDeletedField($tableName);
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        $isTableWorkspaceAware = !empty($workspaceIdField);
        if ($isTableWorkspaceAware && !array_key_exists($workspaceIdField, $row)) {
            throw new \RuntimeException(
                'When soft or hard deleting records from a workspace aware table, t3ver_wsid field must be hand over.',
                1688281493
            );
        }
        if (empty($deleteField)
            || ($isTableWorkspaceAware && ((int)$row[$workspaceIdField] > 0))
        ) {
            return new RecordChange(RecordChangeType::Delete, $tableName, (int)$row['uid']);
        }
        return new RecordChange(
            RecordChangeType::SoftDelete,
            $tableName,
            (int)$row['uid'],
            [
                $deleteField => [
                    'value' => 1,
                    'type' => Connection::PARAM_INT,
                ],
            ]
        );
    }
}
