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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field children that are not deleted but parent is deleted.
 */
class InlineForeignFieldChildrenParentDeleted extends AbstractHealth implements HealthInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with deleted=1 parent');
        $io->text([
            '[UPDATE] TCA inline foreign field records point to a parent record. When this parent is',
            '         soft-deleted (deleted=1), the child should be soft-deleted, too.',
            '         This check finds affected children and updates them to deleted=1.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            if (!$tcaHelper->getDeletedField($childTableName)) {
                // Skip child table if it is not soft-delete aware
                continue;
            }
            $fieldNameOfParentTableName = $inlineChild['fieldNameOfParentTableName'];
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Do not consider deleted records: We want to find children deleted=0 with parents deleted=1.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select('uid', 'pid', $fieldNameOfParentTableName, $fieldNameOfParentTableUid)->from($childTableName)
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
                    // This was handled by previous InlineForeignFieldChildrenParentMissing already.
                    continue;
                }
                $parentTableName = (string)$inlineChildRow[$fieldNameOfParentTableName];
                $parentTableDeleteField = $tcaHelper->getDeletedField($parentTableName);
                if (!$parentTableDeleteField) {
                    // Skip if parent table is not soft-delete aware
                    continue;
                }
                try {
                    $parentRow = $recordsHelper->getRecord((string)$inlineChildRow[$fieldNameOfParentTableName], ['uid', $parentTableDeleteField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    if ((bool)$parentRow[$parentTableDeleteField]) {
                        $inlineChildRow['_reasonBroken'] = 'Deleted parent';
                        $inlineChildRow['_fieldNameOfParentTableName'] = $fieldNameOfParentTableName;
                        $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                        $affectedRows[$childTableName][] = $inlineChildRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Record existence has been checked by InlineForeignFieldChildrenParentMissing already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        foreach ($affectedRecords as $tableName => $tableRows) {
            $deletedField = $tcaHelper->getDeletedField($tableName);
            $updateFields = [
                $deletedField => [
                    'value' => 1,
                    'type' => \PDO::PARAM_INT,
                ],
            ];
            $this->updateAllRecords($io, $simulate, $tableName, $tableRows, $updateFields);
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldNameOfParentTableName'],
                (string)$rows[0]['_fieldNameOfParentTableUid'],
            ];
            $this->outputRecordDetails($io, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
