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

/**
 * Methods used by multiple health classes.
 */
class AbstractHealth
{
    protected ContainerInterface $container;

    public function injectContainer(ContainerInterface $container): void
    {
        $this->container = $container;
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
    protected function deleteRecords(SymfonyStyle $io, array $danglingRows): void
    {
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($danglingRows as $tableName => $rows) {
            $io->note('Deleting records on table: ' . $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $sql = $recordsHelper->deleteTcaRecord($tableName, (int)$row['uid']);
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
    protected function updateRecords(SymfonyStyle $io, string $tableName, array $rows, array $fields): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $io->note('Update records on table: ' . $tableName);
        $count = 0;
        foreach ($rows as $row) {
            $sql = $recordsHelper->updateTcaRecord($tableName, (int)$row['uid'], $fields);
            $io->text($sql);
            $count++;
        }
        $io->warning('Update "' . $count . '" records from "' . $tableName . '" table');
    }
}
