<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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

/**
 * All records in sys_file_reference must point to existing records on left and right side.
 */
class SysFileReferenceInvalidTableLocal extends AbstractHealth implements HealthInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference_records with broken table_local field');
        $io->text([
            '[UPDATE] Records in "sys_file_reference" must have field "table_local" set to',
            '         "sys_file", no exceptions. This check verifies this and can update non-compliant rows.',
        ]);
    }

    protected function getAffectedRecords(): array
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

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->updateAllRecords(
            $io,
            $simulate,
            'sys_file_reference',
            $affectedRecords['sys_file_reference'] ?? [],
            [
                'table_local' => [
                    'value' => 'sys_file',
                    'type' => \PDO::PARAM_STR,
                ],
            ]
        );
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['table_local', 'uid_local', 'tablenames', 'uid_foreign']);
    }
}
