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

use Lolli\Dbhealth\Exception\NoSuchRecordException;
use Lolli\Dbhealth\Exception\NoSuchTableException;
use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * All records in sys_file_reference must be on same pid as the parent record.
 */
class SysFileReferenceInvalidPid extends AbstractHealth implements HealthInterface
{
    private ConnectionPool $connectionPool;

    public function __construct(
        ConnectionPool $connectionPool
    ) {
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference_records with invalid pid');
        $io->text([
            '[UPDATE] Records in "sys_file_reference" must have "pid" set to the same pid as the',
            'parent record: If for instance a tt_content record on pid 5 references a sys_file, the',
            'sys_file_reference record should be on pid 5, too. This check takes care of this.',
        ]);
    }

    public function process(SymfonyStyle $io): int
    {
        $danglingRows = $this->getAffectedRows();
        $this->outputMainSummary($io, $danglingRows);
        if (empty($danglingRows)) {
            return self::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Remove records [y,a,r,p,d,?]?</info> ', '?')) {
                case 'y':
                    $this->updateRecords(
                        $io,
                        'sys_file_reference',
                        $danglingRows['sys_file_reference']
                    );
                    $danglingRows = $this->getAffectedRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows)) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'a':
                    return self::RESULT_ABORT;
                case 'r':
                    $danglingRows = $this->getAffectedRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows)) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputAffectedPages($io, $danglingRows);
                    break;
                case 'd':
                    $this->outputRecordDetails($io, $danglingRows, '', [], ['tablenames', 'uid_foreign', 'fieldname', 'uid_local', 'table_local' ]);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - UPDATE records: Set "pid" to pid of parent record',
                        '    a - abort now',
                        '    r - reload possibly changed data',
                        '    p - show record per page',
                        '    d - show record details',
                        '    ? - print help',
                    ]);
                    break;
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function getAffectedRows(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);
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

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function updateRecords(SymfonyStyle $io, string $tableName, array $rows): void
    {
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
                $this->updateSingleTcaRecord($io, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            } else {
                $referencingRecord = $recordsHelper->getRecord((string)$row['tablenames'], ['pid'], (int)$row['uid_foreign']);
                $fields = [
                    'pid' => [
                        'value' => (int)$referencingRecord['pid'],
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            }
            $count++;
        }
        $io->warning('Update "' . $count . '" records from "' . $tableName . '" table');
    }
}
