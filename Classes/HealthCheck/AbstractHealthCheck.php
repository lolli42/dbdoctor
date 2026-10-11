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
use Doctrine\DBAL\ParameterType;
use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Database\RecordChangeExecutor;
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\MmKey;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Lolli\Dbdoctor\Renderer\AffectedPagesRenderer;
use Lolli\Dbdoctor\Renderer\RecordsRenderer;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Abstract implement by all single health check classes.
 * Has the main scaffolding of health checks and various convenient methods to handle details.
 *
 * Health checks are stateless: State of a single handle() call is in HealthCheckRun. Services
 * are set by inject*() methods instead of a constructor, to keep constructors of checks free
 * for their own services. Checks extending this class must be readonly classes, too.
 */
abstract readonly class AbstractHealthCheck
{
    // Used in IO when a check is fully disabled, for instance due to TYPO3 version
    protected const TAG_DISABLED = 'disabled';
    // Used in IO when a check is considered risky, for instance when it may change FE output
    protected const TAG_RISKY = 'risky';
    // Used in IO when a check may DELETE records
    protected const TAG_REMOVE = 'remove';
    // Used in IO when a check may "deleted=1" records
    protected const TAG_SOFT_DELETE = 'soft-delete';
    // Used in IO when a check may DELETE workspace records
    protected const TAG_WORKSPACE_REMOVE = 'workspace-remove';
    // Used in IO when a check may UPDATE single fields of records
    protected const TAG_UPDATE = 'update-fields';
    // Used in IO when a check may INSERT missing records
    protected const TAG_INSERT = 'insert';

    protected ConnectionPool $connectionPool;
    protected TcaHelper $tcaHelper;
    protected RecordsHelper $recordsHelper;
    protected TableHelper $tableHelper;
    private AffectedPagesRenderer $affectedPagesRenderer;
    private RecordsRenderer $recordsRenderer;
    private RecordChangeExecutor $recordChangeExecutor;

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

    final public function injectAffectedPagesRenderer(AffectedPagesRenderer $affectedPagesRenderer): void
    {
        $this->affectedPagesRenderer = $affectedPagesRenderer;
    }

    final public function injectRecordsRenderer(RecordsRenderer $recordsRenderer): void
    {
        $this->recordsRenderer = $recordsRenderer;
    }

    final public function injectRecordChangeExecutor(RecordChangeExecutor $recordChangeExecutor): void
    {
        $this->recordChangeExecutor = $recordChangeExecutor;
    }

    final public function handle(SymfonyStyle $io, int $mode, string $file): int
    {
        $run = new HealthCheckRun($io, $file, new PreparedStatements());
        try {
            return $this->handleRun($run, $mode);
        } finally {
            $run->statements->release();
        }
    }

    private function handleRun(HealthCheckRun $run, int $mode): int
    {
        $io = $run->io;
        try {
            $findings = $this->detect($run);
            if ($findings === null) {
                return $this->handleLegacyRun($run, $mode);
            }
            return $this->handleFindings($run, $mode, $findings);
        } catch (EarlierCheckNotFixedException $e) {
            // Thrown by detection. Interactive and execute mode detect again later, but
            // only check mode turns this into a skip.
            if ($mode !== HealthCheckInterface::MODE_CHECK) {
                throw $e;
            }
            // Check mode reports findings of earlier checks, but does not fix them. This
            // check relies on those fixes and can not run.
            $this->header($io);
            $io->warning([
                'Check skipped: It relies on findings of earlier checks being fixed.',
                $e->getMessage(),
                'Fix findings of earlier checks, then run check mode again.',
            ]);
            return HealthCheckInterface::RESULT_BROKEN;
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function handleFindings(HealthCheckRun $run, int $mode, array $findings): int
    {
        $io = $run->io;
        if (empty($findings) && !$io->isVerbose()) {
            // Keep the output short: A check without findings is a single line,
            // header and description are shown with -v.
            $io->writeln('<info>OK</info>  ' . (new \ReflectionClass($this))->getShortName());
            return HealthCheckInterface::RESULT_OK;
        }
        $this->header($io);
        $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
        if (empty($findings)) {
            return HealthCheckInterface::RESULT_OK;
        }
        if ($mode === HealthCheckInterface::MODE_CHECK) {
            return HealthCheckInterface::RESULT_BROKEN;
        }
        if ($mode === HealthCheckInterface::MODE_EXECUTE) {
            $this->writeSqlDumpFileHeader($run);
            $this->recordChangeExecutor->execute($run, false, $findings);
            return HealthCheckInterface::RESULT_BROKEN;
        }
        while (true) {
            switch ($io->ask('<info>Handle records [e,s,a,r,p,d,?]?</info> ', '?')) {
                case 'e':
                    $findings = $this->detectFindings($run);
                    $this->writeSqlDumpFileHeader($run);
                    $this->recordChangeExecutor->execute($run, false, $findings);
                    $findings = $this->detectFindings($run);
                    $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
                    if (empty($findings)) {
                        return HealthCheckInterface::RESULT_BROKEN;
                    }
                    break;
                case 's':
                    $findings = $this->detectFindings($run);
                    $this->recordChangeExecutor->execute($run, true, $findings);
                    $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
                    if (empty($findings)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'a':
                    return HealthCheckInterface::RESULT_ABORT;
                case 'r':
                    $findings = $this->detectFindings($run);
                    $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
                    if (empty($findings)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
                    $this->outputFindingsPages($run, $findings);
                    break;
                case 'd':
                    $this->outputMainSummary($io, $this->groupFindingsByTable($findings));
                    $this->outputFindingsDetails($run, $findings);
                    break;
                case 'h':
                default:
                    $io->text($this->getHelp());
                    break;
            }
        }
    }

    /**
     * @return list<Finding>
     */
    private function detectFindings(HealthCheckRun $run): array
    {
        $findings = $this->detect($run);
        if ($findings === null) {
            throw new \LogicException('detect() of a migrated check must not return null.', 1791700002);
        }
        return $findings;
    }

    /**
     * Findings grouped by the table they are counted for: The record table, or the MM table
     * for MM rows of a missing local record.
     *
     * @param list<Finding> $findings
     * @return array<string, list<Finding>>
     */
    private function groupFindingsByTable(array $findings): array
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $tableName = $finding->key instanceof MmKey ? $finding->key->mmTableName : $finding->key->tableName;
            $grouped[$tableName][] = $finding;
        }
        return $grouped;
    }

    /**
     * @param list<Finding> $findings
     */
    private function outputFindingsPages(HealthCheckRun $run, array $findings): void
    {
        $rows = [];
        foreach ($findings as $finding) {
            if ($finding->pid === null || $finding->key instanceof MmKey) {
                continue;
            }
            $rows[$finding->key->tableName][] = [
                'uid' => $finding->key->uid,
                'pid' => $finding->pid,
            ];
        }
        $this->outputAffectedPages($run, $rows);
    }

    /**
     * Record findings are shown with their record, MM findings with their key.
     *
     * @param list<Finding> $findings
     */
    private function outputFindingsDetails(HealthCheckRun $run, array $findings): void
    {
        foreach ($this->groupFindingsByTable($findings) as $tableName => $tableFindings) {
            if ($tableFindings[0]->key instanceof MmKey) {
                $run->io->note('MM table "' . $tableName . '":');
                $header = ['uid_local', 'local table', 'field'];
                $rows = [];
                foreach ($tableFindings as $finding) {
                    /** @var MmKey $key */
                    $key = $finding->key;
                    $row = [$key->uidLocal, $key->localTableName, $key->localFieldName];
                    foreach ($finding->evidence as $value) {
                        $row[] = is_scalar($value) ? (string)$value : json_encode($value, JSON_THROW_ON_ERROR);
                    }
                    $rows[] = $row;
                }
                $run->io->table(array_merge($header, array_keys($tableFindings[0]->evidence)), $rows);
                continue;
            }
            $hasReason = false;
            $extraDbFields = $this->getDetailDbFields($tableName);
            $rows = [];
            foreach ($tableFindings as $finding) {
                $key = $finding->key;
                if ($key instanceof MmKey) {
                    continue;
                }
                if ($key instanceof RecordFieldKey && !in_array($key->fieldName, $extraDbFields, true)) {
                    $extraDbFields[] = $key->fieldName;
                }
                $row = ['uid' => $key->uid];
                if ($finding->reason !== null) {
                    $hasReason = true;
                    $row['_reasonBroken'] = $finding->reason->message;
                }
                $rows[] = $row;
            }
            if ($hasReason) {
                $rows = array_map(static fn(array $row): array => $row + ['_reasonBroken' => ''], $rows);
            }
            $this->outputRecordDetails($run, [$tableName => $rows], $hasReason ? '_reasonBroken' : '', $this->getDetailCtrlFields($tableName), $extraDbFields);
        }
    }

    private function handleLegacyRun(HealthCheckRun $run, int $mode): int
    {
        // @todo Remove with the last check migrated to detect()
        $io = $run->io;
        $affectedRecords = $this->getAffectedRecords($run);
        if (empty($affectedRecords) && !$io->isVerbose()) {
            // Keep the output short: A check without affected records is a single line,
            // header and description are shown with -v.
            $io->writeln('<info>OK</info>  ' . (new \ReflectionClass($this))->getShortName());
            return HealthCheckInterface::RESULT_OK;
        }
        $this->header($io);
        if ($mode === HealthCheckInterface::MODE_CHECK) {
            return $this->check($io, $affectedRecords);
        }
        if ($mode === HealthCheckInterface::MODE_EXECUTE) {
            return $this->execute($run, $affectedRecords);
        }
        return $this->interactive($run, $affectedRecords);
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    private function check(SymfonyStyle $io, array $affectedRecords): int
    {
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }
        return HealthCheckInterface::RESULT_BROKEN;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    private function execute(HealthCheckRun $run, array $affectedRecords): int
    {
        $this->outputMainSummary($run->io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }
        $this->writeSqlDumpFileHeader($run);
        $this->processRecords($run, false, $affectedRecords);
        return HealthCheckInterface::RESULT_BROKEN;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    private function interactive(HealthCheckRun $run, array $affectedRecords): int
    {
        $io = $run->io;
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Handle records [e,s,a,r,p,d,?]?</info> ', '?')) {
                case 'e':
                    $affectedRecords = $this->getAffectedRecords($run);
                    $this->writeSqlDumpFileHeader($run);
                    $this->processRecords($run, false, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords($run);
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_BROKEN;
                    }
                    break;
                case 's':
                    $affectedRecords = $this->getAffectedRecords($run);
                    $this->processRecords($run, true, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords($run);
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'a':
                    return HealthCheckInterface::RESULT_ABORT;
                case 'r':
                    $affectedRecords = $this->getAffectedRecords($run);
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputMainSummary($io, $affectedRecords);
                    $this->affectedPages($run, $affectedRecords);
                    break;
                case 'd':
                    $this->outputMainSummary($io, $affectedRecords);
                    $this->recordDetails($run, $affectedRecords);
                    break;
                case 'h':
                default:
                    $io->text($this->getHelp());
                    break;
            }
        }
    }

    abstract public function header(SymfonyStyle $io): void;

    /**
     * Find broken things, each with the changes that fix it. Checks not migrated yet return
     * null and implement getAffectedRecords() and processRecords() instead.
     *
     * @return list<Finding>|null
     */
    protected function detect(HealthCheckRun $run): ?array
    {
        return null;
    }

    /**
     * Ctrl fields like "transOrigPointerField" shown in record details of findings of a table,
     * additionally to the default fields.
     *
     * @return list<string>
     */
    protected function getDetailCtrlFields(string $tableName): array
    {
        return [];
    }

    /**
     * Database fields shown in record details of findings of a table, additionally to the default
     * fields. Fields of RecordFieldKey findings are added automatically.
     *
     * @return list<string>
     */
    protected function getDetailDbFields(string $tableName): array
    {
        return [];
    }

    /**
     * Legacy, checks not migrated to detect() yet.
     *
     * @return array<string, array<int, array<string, int|string>>>
     */
    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        throw new \LogicException('Implement detect() or getAffectedRecords().', 1791700003);
    }

    /**
     * Legacy, checks not migrated to detect() yet.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        throw new \LogicException('Implement detect() or processRecords().', 1791700004);
    }

    /**
     * Default implementation. Overridden by subclasses sometimes to provide more specific details.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    protected function affectedPages(HealthCheckRun $run, array $affectedRecords): void
    {
        $this->outputAffectedPages($run, $affectedRecords);
    }

    /**
     * Default implementation. Overridden by subclasses sometimes to provide more specific details.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        $this->outputRecordDetails($run, $affectedRecords);
    }

    /**
     * @param array<string, array<int, mixed>> $danglingRows Affected rows or findings per table
     */
    final protected function outputMainSummary(SymfonyStyle $io, array $danglingRows): void
    {
        if (!count($danglingRows)) {
            $io->success('No affected records found');
        } else {
            $ioText = [
                'Found affected records in ' . count($danglingRows) . ' tables:',
            ];
            $tablesString = '';
            foreach ($danglingRows as $tableName => $rows) {
                if (!empty($tablesString)) {
                    $tablesString .= "\n";
                }
                $tablesString .= '"' . $tableName . '": ' . count($rows) . ' records';
            }
            $ioText[] = $tablesString;
            $io->warning($ioText);
        }
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    final protected function outputAffectedPages(HealthCheckRun $run, array $danglingRows): void
    {
        $run->io->note('Found records per page:');
        $affectedPages = $this->affectedPagesRenderer->render($run->statements, $danglingRows);
        $run->io->table($affectedPages['header'], $affectedPages['rows']);
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     * @param array<int, string> $extraCtrlFields
     * @param array<int, string> $extraDbFields
     */
    final protected function outputRecordDetails(HealthCheckRun $run, array $danglingRows, string $reasonField = '', array $extraCtrlFields = [], array $extraDbFields = []): void
    {
        foreach ($danglingRows as $tableName => $rows) {
            $run->io->note('Table "' . $tableName . '":');
            $run->io->table(
                $this->recordsRenderer->getHeader($tableName, $reasonField, $extraCtrlFields, $extraDbFields),
                $this->recordsRenderer->getRows($run->statements, $tableName, $rows, $reasonField, $extraCtrlFields, $extraDbFields)
            );
        }
    }

    /**
     * DELETE multiple records from many TCA tables.
     * Convenient method to save a foreach loop.
     * Calls deleteTcaRecordsOfTable() per table.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    final protected function deleteTcaRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->deleteTcaRecordsOfTable($run, $simulate, $tableName, $tableRows);
        }
    }

    /**
     * DELETE multiple records from a single TCA table.
     * Outputs a summary before and after, while deleteSingleTcaRecord() logs and outputs single queries.
     *
     * @param array<int, array<string, int|string>> $rows
     */
    final protected function deleteTcaRecordsOfTable(HealthCheckRun $run, bool $simulate, string $tableName, array $rows): void
    {
        $this->outputTableDeleteBefore($run, $simulate, $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $this->deleteSingleTcaRecord($run, $simulate, $tableName, (int)$row['uid']);
            $count++;
        }
        $this->outputTableDeleteAfter($run, $simulate, $tableName, $count);
    }

    /**
     * DELETE and log a single row.
     */
    final protected function deleteSingleTcaRecord(HealthCheckRun $run, bool $simulate, string $tableName, int $uid): void
    {
        $this->recordChangeExecutor->executeChange($run, $simulate, new RecordChange(RecordChangeType::Delete, $tableName, $uid));
    }

    /**
     * UPDATE many rows of a single TCA table.
     * Outputs a summary before and after, while updateSingleTcaRecord() logs and outputs single queries.
     *
     * @param array<int, array<string, int|string>> $rows
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    final protected function updateTcaRecordsOfTable(HealthCheckRun $run, bool $simulate, string $tableName, array $rows, array $fields): void
    {
        $this->outputTableUpdateBefore($run, $simulate, $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $this->updateSingleTcaRecord($run, $simulate, $tableName, (int)$row['uid'], $fields);
            $count++;
        }
        $this->outputTableUpdateAfter($run, $simulate, $tableName, $count);
    }

    /**
     * INSERT and log a single row.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    final protected function insertSingleTcaRecord(HealthCheckRun $run, bool $simulate, string $tableName, array $fields): void
    {
        $this->recordChangeExecutor->executeChange($run, $simulate, new RecordChange(RecordChangeType::Insert, $tableName, null, $fields));
    }

    /**
     * UPDATE and log a single row.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    final protected function updateSingleTcaRecord(HealthCheckRun $run, bool $simulate, string $tableName, int $uid, array $fields): void
    {
        $this->recordChangeExecutor->executeChange($run, $simulate, new RecordChange(RecordChangeType::Update, $tableName, $uid, $fields));
    }

    /**
     * DELETE and log rows of an MM table.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $whereFields
     */
    final protected function deleteMmRows(HealthCheckRun $run, bool $simulate, string $mmTableName, array $whereFields): void
    {
        $this->recordChangeExecutor->executeChange($run, $simulate, new RecordChange(RecordChangeType::MmDelete, $mmTableName, null, [], $whereFields));
    }

    /**
     * DELETE or soft-delete multiple records from many tables.
     * Convenient method to save a foreach loop.
     * Calls softOrHardDeleteRecordsOfTable() per table.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    final protected function softOrHardDeleteRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->softOrHardDeleteRecordsOfTable($run, $simulate, $tableName, $tableRows);
        }
    }

    /**
     * DELETE or UPDATE multiple rows of at table:
     * * If the table is not delete-aware, records are DELETED
     * * If the table is delete-aware and the record is live (t3ver_wsid = 0), the record is UPDATED with soft-delete=1
     * * If the record is workspace-aware and the record is within a workspace (t3ver_wsid > 0), the record is DELETED
     *
     * The method outputs a summary before and after, while deleteSingleTcaRecord()
     * and updateSingleTcaRecord() log and output single queries.
     *
     * @param array<int, array<string, int|string>> $rows
     */
    final protected function softOrHardDeleteRecordsOfTable(HealthCheckRun $run, bool $simulate, string $tableName, array $rows): void
    {
        $this->outputTableHandleBefore($run, $simulate, $tableName);
        $updateCount = 0;
        $deleteCount = 0;
        foreach ($rows as $row) {
            $change = $this->softOrHardDeleteChange($tableName, $row);
            $this->recordChangeExecutor->executeChange($run, $simulate, $change);
            if ($change->type === RecordChangeType::Delete) {
                $deleteCount++;
            } else {
                $updateCount++;
            }
        }
        $this->outputTableHandleAfter($run, $simulate, $tableName, $updateCount, $deleteCount);
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

    /**
     * Helper method for header() to render a list of tags
     * to declare the type of changes this check applies.
     *
     * @param string ...$tags
     */
    final protected function outputTags(SymfonyStyle $io, ...$tags): void
    {
        $wrappedTags = [];
        foreach ($tags as $tag) {
            if ($tag === self::TAG_RISKY) {
                $wrappedTags[] = '<error>' . $tag . '</error>';
            } else {
                $wrappedTags[] = '<comment>' . $tag . '</comment>';
            }
        }
        $io->text('Actions: ' . implode(', ', $wrappedTags));
    }

    final protected function outputClass(SymfonyStyle $io): void
    {
        $io->text(('Class: <comment>' . (new \ReflectionClass($this))->getShortName()) . '</comment>');
    }

    final protected function outputTableDeleteBefore(HealthCheckRun $run, bool $simulate, string $tableName): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Delete records on table: ' . $tableName);
        } else {
            $run->io->note('Delete records on table: ' . $tableName);
        }
    }

    final protected function outputTableDeleteAfter(HealthCheckRun $run, bool $simulate, string $tableName, int $count): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Deleted "' . $count . '" records from "' . $tableName . '" table');
        } else {
            $run->io->warning('Deleted "' . $count . '" records from "' . $tableName . '" table');
        }
    }

    final protected function outputTableUpdateBefore(HealthCheckRun $run, bool $simulate, string $tableName): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Update records on table: ' . $tableName);
        } else {
            $run->io->note('Update records on table: ' . $tableName);
        }
    }

    final protected function outputTableUpdateAfter(HealthCheckRun $run, bool $simulate, string $tableName, int $count): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Updated "' . $count . '" records from "' . $tableName . '" table');
        } else {
            $run->io->warning('Updated "' . $count . '" records from "' . $tableName . '" table');
        }
    }

    final protected function outputTableInsertBefore(HealthCheckRun $run, bool $simulate, string $tableName): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Insert records into table: ' . $tableName);
        } else {
            $run->io->note('Insert records into table: ' . $tableName);
        }
    }

    final protected function outputTableInsertAfter(HealthCheckRun $run, bool $simulate, string $tableName, int $count): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Inserted "' . $count . '" records into "' . $tableName . '" table');
        } else {
            $run->io->warning('Inserted "' . $count . '" records into "' . $tableName . '" table');
        }
    }

    final protected function outputTableHandleBefore(HealthCheckRun $run, bool $simulate, string $tableName): void
    {
        if ($simulate) {
            $run->io->note('[SIMULATE] Handle records on table: ' . $tableName);
        } else {
            $run->io->note('Handle records on table: ' . $tableName);
        }
    }

    final protected function outputTableHandleAfter(HealthCheckRun $run, bool $simulate, string $tableName, int $updateCount, int $deleteCount): void
    {
        if ($simulate) {
            if ($updateCount > 0) {
                $run->io->note('[SIMULATE] Updated "' . $updateCount . '" records from "' . $tableName . '" table');
            }
            if ($deleteCount > 0) {
                $run->io->note('[SIMULATE] Deleted "' . $deleteCount . '" records from "' . $tableName . '" table');
            }
        } else {
            if ($updateCount > 0) {
                $run->io->warning('Updated "' . $updateCount . '" records from "' . $tableName . '" table');
            }
            if ($deleteCount > 0) {
                $run->io->warning('Deleted "' . $deleteCount . '" records from "' . $tableName . '" table');
            }
        }
    }

    /**
     * Comment "Triggered by" in the dump file before each pass of changes, to find out
     * which specific health check triggered the following statements when reading the
     * dump file. A service with multiple tags runs at multiple chain positions, each pass
     * gets its own header.
     */
    private function writeSqlDumpFileHeader(HealthCheckRun $run): void
    {
        if ($run->sqlDumpFile) {
            file_put_contents($run->sqlDumpFile, '# Triggered by ' . static::class . "\n", \FILE_APPEND);
        }
    }

    /**
     * @return string[]
     */
    private function getHelp(): array
    {
        $help = [];
        $help[] = '    e - EXECUTE suggested changes!';
        $help[] = '    s - SIMULATE suggested changes, no execution';
        $help[] = '    a - ABORT now';
        $help[] = '    r - RELOAD this check';
        $help[] = '    p - SHOW records by page';
        $help[] = '    d - SHOW record details';
        $help[] = '    ? - HELP';
        return $help;
    }
}
