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
 * sys_file_reference rows where either uid_local or uid_foreign does not exist.
 */
final class SysFileReferenceDangling extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for orphan sys_file_reference records');
        $io->text([
            '[DELETE] A basic check for sys_file_reference: the records referenced in uid_local',
            '         and uid_foreign must exist, otherwise that sys_file_reference row is obsolete and',
            '         should be removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $danglingRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        // We fetch deleted=1 records here, too. If it's relation is broken, they should vanish, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'uid_local', 'uid_foreign', 'tablenames')
            ->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $recordsHelper->getRecord('sys_file', ['uid'], (int)$row['uid_local']);
                $recordsHelper->getRecord((string)$row['tablenames'], ['uid'], (int)$row['uid_foreign']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                $danglingRows['sys_file_reference'][] = $row;
            }
        }
        return $danglingRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($io, $simulate, 'sys_file_reference', $affectedRecords['sys_file_reference'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['uid_local', 'tablenames', 'uid_foreign', 'fieldname']);
    }
}
