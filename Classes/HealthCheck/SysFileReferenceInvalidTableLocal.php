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

use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * All records in "sys_file_reference" have "table_local" set to "sys_file".
 *
 * Note core v12 no longer provides field "table_local", this check is skipped in v12.
 */
final class SysFileReferenceInvalidTableLocal extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference_records with broken table_local field');
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            $io->text([
                '[SKIPPED] This check is obsolete with core v12.',
            ]);
        } else {
            $io->text([
                '[UPDATE] Records in "sys_file_reference" must have field "table_local" set to',
                '         "sys_file". This check verifies this and can update non-compliant rows.',
            ]);
        }
    }

    protected function getAffectedRecords(): array
    {
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            // Skip with TYPO3 v12.
            return [];
        }

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
