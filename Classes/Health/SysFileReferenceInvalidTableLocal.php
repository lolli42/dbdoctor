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

use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * All records in sys_file_reference must point to existing records on left and right side.
 */
class SysFileReferenceInvalidTableLocal extends AbstractHealth implements HealthInterface
{
    private ConnectionPool $connectionPool;

    public function __construct(
        ConnectionPool $connectionPool
    ) {
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference_records with broken table_local field');
        $io->text([
            '[UPDATE] Records in "sys_file_reference" must have field "table_local" set to',
            '"sys_file", no exceptions. This check verifies this and can update non-compliant rows.',
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
                    $this->updateAllRecords(
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
                    $this->outputRecordDetails($io, $danglingRows, '', [], ['table_local', 'uid_local', 'tablenames', 'uid_foreign']);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - UPDATE records: Set "table_local" = "sys_file" ',
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
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid')->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->neq('table_local', $queryBuilder->createNamedParameter('sys_file'))
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $tableRows['sys_file_reference'][] = $row;
        }
        return $tableRows;
    }
}
