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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * All records in sys_file_reference must be on same pid as the parent record.
 */
final class SysFileReferenceInvalidPid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference records with invalid pid');
        $io->text([
            '[UPDATE] Records in "sys_file_reference" must have "pid" set to the same pid as the',
            '         parent record: If for instance a tt_content record on pid 5 references a sys_file, the',
            '         sys_file_reference record should be on pid 5, too. This check takes care of this.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'uid_foreign', 'tablenames')->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            if ((string)$row['tablenames'] === 'pages') {
                if ((int)$row['uid_foreign'] !== (int)$row['pid']
                    && (int)$row['sys_language_uid'] === 0
                ) {
                    // For sys_file_reference's attached to pages records, it's simple: "pid"
                    // and "uid_foreign" must be the same, if we're dealing with sys_language_uid=0 records
                    $tableRows['sys_file_reference'][] = $row;
                }
            } else {
                try {
                    $referencingRecord = $recordsHelper->getRecord((string)$row['tablenames'], ['pid'], (int)$row['uid_foreign']);
                } catch (NoSuchRecordException|NoSuchTableException $e) {
                    // Table and record existence has been checked by SysFileReferenceDangling already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
                if ((int)$row['pid'] !== (int)$referencingRecord['pid']) {
                    /** @var array<string, int|string> $row */
                    $tableRows['sys_file_reference'][] = $row;
                }
            }
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $tableName = 'sys_file_reference';
        $rows = $affectedRecords['sys_file_reference'] ?? [];
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $io->note('Update records on table: ' . $tableName);
        $count = 0;
        foreach ($rows as $row) {
            if ($row['tablenames'] === 'pages') {
                $fields = [
                    'pid' => [
                        'value' => (int)$row['uid_foreign'],
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            } else {
                $referencingRecord = $recordsHelper->getRecord((string)$row['tablenames'], ['pid'], (int)$row['uid_foreign']);
                $fields = [
                    'pid' => [
                        'value' => (int)$referencingRecord['pid'],
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            }
            $count++;
        }
        $io->warning('Update "' . $count . '" records from "' . $tableName . '" table');
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['tablenames', 'uid_foreign', 'fieldname', 'uid_local']);
    }
}
