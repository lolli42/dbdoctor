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
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Lolli\Dbdoctor\Renderer\AffectedPagesRenderer;
use Lolli\Dbdoctor\Renderer\RecordsRenderer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Abstract implement by all single health check classes.
 * Has the main scaffolding of health checks and various convenient methods to handle details.
 */
abstract class AbstractHealthCheck
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

    protected ContainerInterface $container;
    protected ConnectionPool $connectionPool;
    protected TcaHelper $tcaHelper;

    final public function injectContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    final public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    final public function injectTcaHelper(TcaHelper $tcaHelper): void
    {
        $this->tcaHelper = $tcaHelper;
    }

    final public function handle(SymfonyStyle $io, int $mode, string $file): int
    {
        try {
            $affectedRecords = $this->getAffectedRecords();
        } catch (EarlierCheckNotFixedException $e) {
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
        $run = new HealthCheckRun($io, $file);
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
                    $affectedRecords = $this->getAffectedRecords();
                    $this->writeSqlDumpFileHeader($run);
                    $this->processRecords($run, false, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_BROKEN;
                    }
                    break;
                case 's':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->processRecords($run, true, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'a':
                    return HealthCheckInterface::RESULT_ABORT;
                case 'r':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputMainSummary($io, $affectedRecords);
                    $this->affectedPages($io, $affectedRecords);
                    break;
                case 'd':
                    $this->outputMainSummary($io, $affectedRecords);
                    $this->recordDetails($io, $affectedRecords);
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
     * @return array<string, array<int, array<string, int|string>>>
     */
    abstract protected function getAffectedRecords(): array;

    /**
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    abstract protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void;

    /**
     * Default implementation. Overridden by subclasses sometimes to provide more specific details.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    protected function affectedPages(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputAffectedPages($io, $affectedRecords);
    }

    /**
     * Default implementation. Overridden by subclasses sometimes to provide more specific details.
     *
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords);
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
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
    final protected function outputAffectedPages(SymfonyStyle $io, array $danglingRows): void
    {
        $io->note('Found records per page:');
        /** @var AffectedPagesRenderer $affectedPagesHelper */
        $affectedPagesHelper = $this->container->get(AffectedPagesRenderer::class);
        $io->table($affectedPagesHelper->getHeader($danglingRows), $affectedPagesHelper->getRows($danglingRows));
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     * @param array<int, string> $extraCtrlFields
     * @param array<int, string> $extraDbFields
     */
    final protected function outputRecordDetails(SymfonyStyle $io, array $danglingRows, string $reasonField = '', array $extraCtrlFields = [], array $extraDbFields = []): void
    {
        /** @var RecordsRenderer $recordsRenderer */
        $recordsRenderer = $this->container->get(RecordsRenderer::class);
        foreach ($danglingRows as $tableName => $rows) {
            $io->note('Table "' . $tableName . '":');
            $io->table(
                $recordsRenderer->getHeader($tableName, $reasonField, $extraCtrlFields, $extraDbFields),
                $recordsRenderer->getRows($tableName, $rows, $reasonField, $extraCtrlFields, $extraDbFields)
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
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $this->outputTableDeleteBefore($run, $simulate, $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $this->deleteSingleTcaRecord($run, $simulate, $recordsHelper, $tableName, (int)$row['uid']);
            $count++;
        }
        $this->outputTableDeleteAfter($run, $simulate, $tableName, $count);
    }

    /**
     * DELETE and log a single row.
     * This needs an instance of RecordsHelper to make use of prepared statements, which
     * should be created by the calling method.
     */
    final protected function deleteSingleTcaRecord(HealthCheckRun $run, bool $simulate, RecordsHelper $recordsHelper, string $tableName, int $uid): void
    {
        $sql = $recordsHelper->deleteTcaRecord($simulate, $tableName, $uid);
        $this->logAndOutputSql($run, $simulate, $sql);
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
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $this->outputTableUpdateBefore($run, $simulate, $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $this->updateSingleTcaRecord($run, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            $count++;
        }
        $this->outputTableUpdateAfter($run, $simulate, $tableName, $count);
    }

    /**
     * INSERT and log a single row.
     * This needs an instance of RecordsHelper to make use of prepared statements, which
     * should be created by the calling method.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    final protected function insertSingleTcaRecord(HealthCheckRun $run, bool $simulate, RecordsHelper $recordsHelper, string $tableName, array $fields): void
    {
        $sql = $recordsHelper->insertTcaRecord($simulate, $tableName, $fields);
        $this->logAndOutputSql($run, $simulate, $sql);
    }

    /**
     * UPDATE and log a single row.
     * This needs an instance of RecordsHelper to make use of prepared statements, which
     * should be created by the calling method.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    final protected function updateSingleTcaRecord(HealthCheckRun $run, bool $simulate, RecordsHelper $recordsHelper, string $tableName, int $uid, array $fields): void
    {
        $sql = $recordsHelper->updateTcaRecord($simulate, $tableName, $uid, $fields);
        $this->logAndOutputSql($run, $simulate, $sql);
    }

    /**
     * DELETE and log rows of an MM table.
     * This needs an instance of RecordsHelper to make use of prepared statements, which
     * should be created by the calling method.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $whereFields
     */
    final protected function deleteMmRows(HealthCheckRun $run, bool $simulate, RecordsHelper $recordsHelper, string $mmTableName, array $whereFields): void
    {
        $sql = $recordsHelper->deleteMmRows($simulate, $mmTableName, $whereFields);
        $this->logAndOutputSql($run, $simulate, $sql);
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
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $this->outputTableHandleBefore($run, $simulate, $tableName);

        $deleteField = $this->tcaHelper->getDeletedField($tableName);
        $isTableSoftDeleteAware = !empty($deleteField);
        $updateFields = [];
        if ($isTableSoftDeleteAware) {
            $updateFields = [
                $deleteField => [
                    'value' => 1,
                    'type' => Connection::PARAM_INT,
                ],
            ];
        }
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        $isTableWorkspaceAware = !empty($workspaceIdField);

        $updateCount = 0;
        $deleteCount = 0;
        foreach ($rows as $row) {
            if ($isTableWorkspaceAware && !array_key_exists($workspaceIdField, $row)) {
                throw new \RuntimeException(
                    'When soft or hard deleting records from a workspace aware table, t3ver_wsid field must be hand over.',
                    1688281493
                );
            }
            if (!$isTableSoftDeleteAware
                || ($isTableWorkspaceAware && ((int)$row[$workspaceIdField] > 0))
            ) {
                // DELETE record if table is not workspace aware, or if record is a workspace record
                $this->deleteSingleTcaRecord($run, $simulate, $recordsHelper, $tableName, (int)$row['uid']);
                $deleteCount++;
            } else {
                // UPDATE record, set "deleted=1" if table is soft-delete aware and record is not a workspace record
                $this->updateSingleTcaRecord($run, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $updateFields);
                $updateCount++;
            }
        }

        $this->outputTableHandleAfter($run, $simulate, $tableName, $updateCount, $deleteCount);
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

    private function logAndOutputSql(HealthCheckRun $run, bool $simulate, string $sql): void
    {
        if ($run->sqlDumpFile && !$simulate) {
            file_put_contents($run->sqlDumpFile, $sql . "\n", \FILE_APPEND);
        }
        $run->io->text($sql);
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
