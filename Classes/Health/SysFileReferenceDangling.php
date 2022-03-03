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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * sys_file_reference rows where either uid_local or uid_foreign does not exist.
 */
class SysFileReferenceDangling extends AbstractHealth implements HealthInterface
{
    private ConnectionPool $connectionPool;

    public function __construct(
        ConnectionPool $connectionPool
    ) {
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for orphan sys_file_reference records');
        $io->text([
            '[DELETE] A basic check for sys_file_reference: the records referenced in uid_local',
            'and uid_foreign must exist, otherwise that sys_file_reference row is obsolete and',
            'should be removed.',
        ]);
    }

    public function process(SymfonyStyle $io): int
    {
        $danglingRows = $this->getDanglingRows();
        $this->outputMainSummary($io, $danglingRows);
        if (empty($danglingRows)) {
            return self::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Remove records [y,a,r,p,d,?]?</info> ', '?')) {
                case 'y':
                    $this->deleteRecords($io, $danglingRows);
                    $danglingRows = $this->getDanglingRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows['pages'])) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'a':
                    return self::RESULT_ABORT;
                case 'r':
                    $danglingRows = $this->getDanglingRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows)) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputAffectedPages($io, $danglingRows);
                    break;
                case 'd':
                    $this->outputRecordDetails($io, $danglingRows, '', [], ['table_local', 'uid_local', 'tablenames', 'uid_foreign']);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - DELETE - no soft-delete - records',
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
    private function getDanglingRows(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $danglingRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        // We fetch deleted=1 records here, too. If it's relation is broken, they should vanish, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'uid_local', 'table_local', 'uid_foreign', 'tablenames')
            ->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $recordsHelper->getRecord((string)$row['table_local'], ['uid'], (int)$row['uid_local']);
                $recordsHelper->getRecord((string)$row['tablenames'], ['uid'], (int)$row['uid_foreign']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                $danglingRows['sys_file_reference'][] = $row;
            }
        }
        return $danglingRows;
    }
}
