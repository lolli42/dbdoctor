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

use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remove records pointing to not existing 'fieldname' fields on foreign table
 *
 * @todo: This check is a bit risky and currently not enabled in HealthFactory:
 *        - First, it gives headaches with 'flex' fields and already skips
 *          records that point to a parent table which has *any* flex field configured.
 *          Looking especially at you, tt_content!
 *        - Second, the (v12 deprecated) EMU::getFileFieldTCAConfig() allowed to set
 *          ['foreign_match_fields']['fieldname'] to something different than the
 *          column name of the parent table. That's probably problematic to do, but it
 *          seems at least the Backend can deal with this?
 */
class SysFileReferenceInvalidFieldname extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file_reference_records with invalid foreign fieldname');
        $io->text([
            '[DELETE] Records in "sys_file_reference" point to a foreign table in "tablenames" and',
            '         a foreign field in "fieldname". This check verifies the "fieldname" exists in the foreign',
            '         table, and deletes sys_file_reference records that point to invalid "fieldname".',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        // We consider deleted=1 records too to remove them if they're invalid.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'tablenames', 'fieldname')->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            if (!$tableHelper->fieldExistsInTable((string)$row['tablenames'], (string)$row['fieldname'])
                && !$tcaHelper->hasFlexField((string)$row['tablenames'])
            ) {
                // @todo: FAL fields can be bound to TCA flex. For those, fieldname is set to single flex
                //        data structure fields names. (see styleguide_inline_fal flex example) To find out
                //        if a fieldname is valid, we'd have to parse the data structure and see if there
                //        is a field with that name.
                //        For now, we simply skip those row as soon if the table has at least one flex column.
                $tableRows['sys_file_reference'][] = $row;
            }
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteAllRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['tablenames', 'uid_foreign', 'fieldname', 'uid_local']);
    }
}
