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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * All records in sys_file_reference must be on same pid as the parent record.
 */
class InvalidPidInSysFileReferenceRecords extends AbstractHealth implements HealthInterface
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
                        $danglingRows['sys_file_reference'],
                        ['table_local' => ['value' => 'sys_file', 'type' => \PDO::PARAM_STR]]
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
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'uid_foreign', 'tablenames')->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            if ($row['tablenames'] === 'pages') {
                // For sys_file_reference's attached to pages records, it's simple: "pid"
                // and "uid_foreign" muste be the same.
                // @todo: not if the're page translations.
                if ((int)$row['uid_foreign'] !== (int)$row['pid']) {
                    $tableRows['sys_file_reference'][] = $row;
                }
            } else {
                $referencingRecord = $recordsHelper->getRecord((string)$row['tablenames'], ['pid'], (int)$row['uid_foreign']);
                if ((int)$row['pid'] !== (int)$referencingRecord['pid']) {
                    /** @var array<string, int|string> $row */
                    $tableRows['sys_file_reference'][] = $row;
                }
            }
        }
        return $tableRows;
    }
}
