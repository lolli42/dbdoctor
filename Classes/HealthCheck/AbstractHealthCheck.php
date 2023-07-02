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
use Lolli\Dbdoctor\Helper\TcaHelper;
use Lolli\Dbdoctor\Renderer\AffectedPagesRenderer;
use Lolli\Dbdoctor\Renderer\RecordsRenderer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Methods used by multiple health classes.
 */
abstract class AbstractHealthCheck
{
    /**
     * Set to an absolute, not-empty file path string when sql command should be logged.
     */
    private string $sqlDumpFile;

    /**
     * Set to true as soon as a health check got a first change and wrote a comment
     * "Triggered by" to $sqlDumpFile. This helps to find out which specific health check
     * triggered a change when reading the dump file. It is used to ensure this comment
     * header is only wrote once per single health check on first SQL change.
     */
    private bool $sqlDumpFileHeaderWritten = false;

    protected ContainerInterface $container;
    protected ConnectionPool $connectionPool;
    protected TcaHelper $tcaHelper;

    public function injectContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    public function injectTcaHelper(TcaHelper $tcaHelper): void
    {
        $this->tcaHelper = $tcaHelper;
    }

    public function handle(SymfonyStyle $io, int $mode, string $file): int
    {
        $this->sqlDumpFile = $file;
        if ($mode === HealthCheckInterface::MODE_CHECK) {
            return $this->check($io);
        }
        if ($mode === HealthCheckInterface::MODE_EXECUTE) {
            return $this->execute($io);
        }
        return $this->interactive($io);
    }

    private function check(SymfonyStyle $io): int
    {
        $affectedRecords = $this->getAffectedRecords();
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }
        return HealthCheckInterface::RESULT_BROKEN;
    }

    private function execute(SymfonyStyle $io): int
    {
        $affectedRecords = $this->getAffectedRecords();
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }
        $this->processRecords($io, false, $affectedRecords);
        return HealthCheckInterface::RESULT_BROKEN;
    }

    private function interactive(SymfonyStyle $io): int
    {
        $affectedRecords = $this->getAffectedRecords();
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthCheckInterface::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Handle records [e,s,a,r,p,d,?]?</info> ', '?')) {
                case 'e':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->processRecords($io, false, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthCheckInterface::RESULT_BROKEN;
                    }
                    break;
                case 's':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->processRecords($io, true, $affectedRecords);
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

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    abstract protected function getAffectedRecords(): array;

    /**
     * @param array<string, array<int, array<string, int|string>>> $affectedRecords
     */
    abstract protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void;

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
    protected function outputMainSummary(SymfonyStyle $io, array $danglingRows): void
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
    protected function outputAffectedPages(SymfonyStyle $io, array $danglingRows): void
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
    protected function outputRecordDetails(
        SymfonyStyle $io,
        array $danglingRows,
        string $reasonField = '',
        array $extraCtrlFields = [],
        array $extraDbFields = []
    ): void {
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
     * @todo: Make this 'per-table' just as updateAllRecords()
     *
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    protected function deleteAllRecords(SymfonyStyle $io, bool $simulate, array $danglingRows): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($danglingRows as $tableName => $rows) {
            if ($simulate) {
                $io->note('[SIMULATE] deleting records on table: ' . $tableName);
            } else {
                $io->note('Deleting records on table: ' . $tableName);
            }
            $count = 0;
            foreach ($rows as $row) {
                $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid']);
                $count ++;
            }
            if ($simulate) {
                $io->note('[SIMULATE] Deleted "' . $count . '" records from "' . $tableName . '" table');
            } else {
                $io->warning('Deleted "' . $count . '" records from "' . $tableName . '" table');
            }
        }
    }

    protected function deleteSingleTcaRecord(
        SymfonyStyle $io,
        bool $simulate,
        RecordsHelper $recordsHelper,
        string $tableName,
        int $uid
    ): void {
        $sql = $recordsHelper->deleteTcaRecord($simulate, $tableName, $uid);
        $this->logAndOutputSql($io, $simulate, $sql);
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @param array<string, array<string, int|string>> $fields
     */
    protected function updateAllRecords(SymfonyStyle $io, bool $simulate, string $tableName, array $rows, array $fields): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        if ($simulate) {
            $io->note('[SIMULATE] Update records on table: ' . $tableName);
        } else {
            $io->note('Update records on table: ' . $tableName);
        }
        $count = 0;
        foreach ($rows as $row) {
            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            $count++;
        }
        if ($simulate) {
            $io->note('[SIMULATE] Update "' . $count . '" records from "' . $tableName . '" table');
        } else {
            $io->warning('Updated "' . $count . '" records from "' . $tableName . '" table');
        }
    }

    /**
     * @param array<string, array<string, int|string>> $fields
     */
    protected function updateSingleTcaRecord(
        SymfonyStyle $io,
        bool $simulate,
        RecordsHelper $recordsHelper,
        string $tableName,
        int $uid,
        array $fields
    ): void {
        $sql = $recordsHelper->updateTcaRecord($simulate, $tableName, $uid, $fields);
        $this->logAndOutputSql($io, $simulate, $sql);
    }

    /**
     * When deleting records, they should be soft-deleted when in live (t3ver_wsid = 0),
     * but should be removed when in workspaces (t3ver_wsid > 0). This helper method
     * handles both.
     *
     * @param array<int, array<string, int|string>> $rows
     */
    protected function softOrHardDeleteRecords(SymfonyStyle $io, bool $simulate, string $tableName, array $rows): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        if ($simulate) {
            $io->note('[SIMULATE] Handle records on table: ' . $tableName);
        } else {
            $io->note('Handle records on table: ' . $tableName);
        }

        $deleteField = $this->tcaHelper->getDeletedField($tableName);
        $isTableSoftDeleteAware = !empty($deleteField);
        $updateFields = [];
        if ($isTableSoftDeleteAware) {
            $updateFields = [
                $deleteField => [
                    'value' => 1,
                    'type' => \PDO::PARAM_INT,
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
                $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid']);
                $deleteCount ++;
            } else {
                // UPDATE record, set "deleted=1" if table is soft-delete aware and record is not a workspace record
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $updateFields);
                $updateCount ++;
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

    private function logAndOutputSql(SymfonyStyle $io, bool $simulate, string $sql): void
    {
        if ($this->sqlDumpFile && !$simulate) {
            if (!$this->sqlDumpFileHeaderWritten) {
                // Write a header to the dump file before first row is logged
                file_put_contents($this->sqlDumpFile, '# Triggered by ' . static::class . "\n", \FILE_APPEND);
                $this->sqlDumpFileHeaderWritten = true;
            }
            // Write that statement to file
            file_put_contents($this->sqlDumpFile, $sql . "\n", \FILE_APPEND);
        }
        $io->text($sql);
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
