<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Health;

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

use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Renderer\AffectedPagesRenderer;
use Lolli\Dbhealth\Renderer\RecordsRenderer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Methods used by multiple health classes.
 */
abstract class AbstractHealth
{
    protected ContainerInterface $container;
    protected ConnectionPool $connectionPool;

    public function injectContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    public function handle(SymfonyStyle $io, bool $simulate): int
    {
        if ($simulate) {
            return $this->simulate($io);
        }
        return $this->interactive($io);
    }

    protected function simulate(SymfonyStyle $io): int
    {
        $affectedRecords = $this->getAffectedRecords();
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthInterface::RESULT_OK;
        }
        $this->processRecords($io, true, $affectedRecords);
        return HealthInterface::RESULT_BROKEN;
    }

    protected function interactive(SymfonyStyle $io): int
    {
        $affectedRecords = $this->getAffectedRecords();
        $this->outputMainSummary($io, $affectedRecords);
        if (empty($affectedRecords)) {
            return HealthInterface::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Handle records [e,s,a,r,p,d,?]?</info> ', '?')) {
                case 'e':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->processRecords($io, false, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthInterface::RESULT_OK;
                    }
                    break;
                case 's':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->processRecords($io, true, $affectedRecords);
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthInterface::RESULT_OK;
                    }
                    break;
                case 'a':
                    return HealthInterface::RESULT_ABORT;
                case 'r':
                    $affectedRecords = $this->getAffectedRecords();
                    $this->outputMainSummary($io, $affectedRecords);
                    if (empty($affectedRecords)) {
                        return HealthInterface::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->affectedPages($io, $affectedRecords);
                    break;
                case 'd':
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
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    protected function deleteRecords(SymfonyStyle $io, bool $simulate, array $danglingRows): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($danglingRows as $tableName => $rows) {
            $io->note('Deleting records on table: ' . $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $sql = $recordsHelper->deleteTcaRecord($simulate, $tableName, (int)$row['uid']);
                $io->text($sql);
                $count ++;
            }
            $io->warning('Deleted "' . $count . '" records from "' . $tableName . '" table');
        }
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @param array<string, array<string, int|string>> $fields
     */
    protected function updateAllRecords(SymfonyStyle $io, bool $simulate, string $tableName, array $rows, array $fields): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $io->note('Update records on table: ' . $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            $count++;
        }
        $io->warning('Update "' . $count . '" records from "' . $tableName . '" table');
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
        $io->text($sql);
    }

    /**
     * @return string[]
     */
    private function getHelp(): array
    {
        $help = [];
        if ($this instanceof HealthDeleteInterface) {
            $help[] = '    e - DELETE records - No soft-delete!';
        }
        if ($this instanceof HealthUpdateInterface) {
            $help[] = '    e - UPDATE records';
        }
        $help[] = '    s - show queries but do not execute them';
        $help[] = '    a - abort now';
        $help[] = '    r - reload possibly changed data';
        $help[] = '    p - show record per page';
        $help[] = '    d - show record details';
        $help[] = '    ? - print help';
        return $help;
    }
}
