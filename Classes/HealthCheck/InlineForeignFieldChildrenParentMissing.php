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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inline foreign field children must have existing parent record.
 */
final class InlineForeignFieldChildrenParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with missing parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'TCA inline foreign field records point to a parent record. This parent must exist.',
            'This check is for inline children defined *with* foreign_table_field in TCA.',
            'Inline children with missing parent are deleted.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $fieldNameOfParentTableName = $inlineChild['fieldNameOfParentTableName'];
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Consider deleted records: If the parent does not exist, they should be deleted, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $fieldNameOfParentTableName, $fieldNameOfParentTableUid)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->neq($fieldNameOfParentTableName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull($fieldNameOfParentTableName)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                if (empty($inlineChildRow[$fieldNameOfParentTableName])
                    || (int)($inlineChildRow[$fieldNameOfParentTableUid]) === 0
                    // Parent TCA table must be defined and table must exist
                    || !is_array($GLOBALS['TCA'][$inlineChildRow[$fieldNameOfParentTableName]] ?? false)
                    || !$tableHelper->tableExistsInDatabase((string)$inlineChildRow[$fieldNameOfParentTableName])
                ) {
                    $inlineChildRow['_reasonBroken'] = 'Invalid parent';
                    $inlineChildRow['_fieldNameOfParentTableName'] = $fieldNameOfParentTableName;
                    $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                    $affectedRows[$childTableName][] = $inlineChildRow;
                    continue;
                }
                try {
                    $this->recordsHelper->getRecord($run->statements, (string)$inlineChildRow[$fieldNameOfParentTableName], ['uid'], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                } catch (NoSuchRecordException $e) {
                    $inlineChildRow['_reasonBroken'] = 'Missing parent';
                    $inlineChildRow['_fieldNameOfParentTableName'] = $fieldNameOfParentTableName;
                    $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                    $affectedRows[$childTableName][] = $inlineChildRow;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecords($run, $simulate, $affectedRecords);
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldNameOfParentTableName'],
                (string)$rows[0]['_fieldNameOfParentTableUid'],
            ];
            $this->outputRecordDetails($run, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
