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

use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Relations in TCA type 'group' fields without MM table must point to existing records.
 */
final class GroupFieldRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for group fields with relations to missing records');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'Fields of TCA type "group" without MM table store their relations as comma',
            'separated list, for instance the tt_content field "records" of content type',
            '"Insert records". This check finds relations to records that do not exist',
            'and removes them from the list. Relations to soft-deleted records are kept:',
            'The backend does not remove them when a record is deleted, and they are',
            'needed when a record is restored using the recycler.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextGroupFieldWithoutMm() as $groupField) {
            $tableName = $groupField['tableName'];
            $fieldName = $groupField['fieldName'];
            if (!$tableHelper->fieldExistsInTable($tableName, $fieldName)) {
                continue;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records, too.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $fieldName)->from($tableName);
            if ($tableHelper->fieldIsInteger($tableName, $fieldName)) {
                $queryBuilder->where(
                    $queryBuilder->expr()->neq($fieldName, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
            } else {
                $queryBuilder->where(
                    $queryBuilder->expr()->neq($fieldName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull($fieldName)
                );
            }
            $result = $queryBuilder->orderBy('uid')->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $missingRelations = $this->getMissingRelations($recordsHelper, $tableHelper, (string)$row[$fieldName], $groupField['allowedTables']);
                if (!empty($missingRelations) && $tableName === 'sys_file_reference' && $fieldName === 'uid_local') {
                    // Emptying uid_local would leave a broken file reference, it must be removed instead.
                    throw new EarlierCheckNotFixedException(
                        'sys_file_reference record with uid="' . $row['uid'] . '" has uid_local="' . $row[$fieldName] . '",'
                        . ' but that sys_file does not exist. The earlier check SysFileReferenceDangling finds and fixes this.',
                        1791633600
                    );
                }
                if (!empty($missingRelations)) {
                    $affectedRows[$tableName][] = [
                        'uid' => (int)$row['uid'],
                        'pid' => (int)$row['pid'],
                        '_fieldName' => $fieldName,
                        '_fieldValue' => (string)$row[$fieldName],
                        '_allowedTables' => implode(',', $groupField['allowedTables']),
                        '_reasonBroken' => 'Field "' . $fieldName . '": Missing ' . implode(', ', array_keys($missingRelations)),
                    ];
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);
        foreach ($affectedRecords as $tableName => $rows) {
            $this->outputTableUpdateBefore($io, $simulate, $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $fieldName = (string)$row['_fieldName'];
                $allowedTables = GeneralUtility::trimExplode(',', (string)$row['_allowedTables'], true);
                $missingRelations = $this->getMissingRelations($recordsHelper, $tableHelper, (string)$row['_fieldValue'], $allowedTables);
                $remainingItems = [];
                foreach (GeneralUtility::trimExplode(',', (string)$row['_fieldValue'], true) as $item) {
                    if (!in_array($item, $missingRelations, true)) {
                        $remainingItems[] = $item;
                    }
                }
                if (empty($remainingItems) && $tableHelper->fieldIsInteger($tableName, $fieldName)) {
                    $updateFields = [
                        $fieldName => [
                            'value' => 0,
                            'type' => Connection::PARAM_INT,
                        ],
                    ];
                } else {
                    $updateFields = [
                        $fieldName => [
                            'value' => implode(',', $remainingItems),
                            'type' => Connection::PARAM_STR,
                        ],
                    ];
                }
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $updateFields);
                $count++;
            }
            $this->outputTableUpdateAfter($io, $simulate, $tableName, $count);
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $fieldNames = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['_fieldName'], $rows)));
            $this->outputRecordDetails($io, [$tableName => $rows], '_reasonBroken', [], $fieldNames);
        }
    }

    /**
     * Parse a comma separated list of a group field like core RelationHandler->readList() does,
     * and return the items that point to a not existing record. Items like "tt_content_42"
     * point to a record of the given table, items like "42" point to a record of the first
     * allowed table. Items that can not be resolved to an allowed table and a positive uid,
     * or that point to a table that does not exist, are ignored and kept.
     *
     * @param array<int, string> $allowedTables
     * @return array<string, string> Key is "tableName:uid", value is the item as stored in the list
     */
    private function getMissingRelations(RecordsHelper $recordsHelper, TableHelper $tableHelper, string $fieldValue, array $allowedTables): array
    {
        $isAnyTableAllowed = in_array('*', $allowedTables, true);
        $firstTable = $isAnyTableAllowed ? '' : ($allowedTables[0] ?? '');
        $missingRelations = [];
        foreach (GeneralUtility::trimExplode(',', $fieldValue, true) as $item) {
            // "tt_content_42": Split at last underscore. Table names may contain underscores.
            $parts = explode('_', strrev($item), 2);
            $uid = strrev($parts[0]);
            if (!MathUtility::canBeInterpretedAsInteger($uid) || (int)$uid <= 0) {
                continue;
            }
            $targetTableName = ($parts[1] ?? '') !== '' ? strrev($parts[1]) : $firstTable;
            if ($targetTableName === ''
                || (!$isAnyTableAllowed && !in_array($targetTableName, $allowedTables, true))
                || !is_array($GLOBALS['TCA'][$targetTableName] ?? false)
                || !$tableHelper->tableExistsInDatabase($targetTableName)
            ) {
                continue;
            }
            try {
                $recordsHelper->getRecord($targetTableName, ['uid'], (int)$uid);
            } catch (NoSuchRecordException $e) {
                $missingRelations[$targetTableName . ':' . (int)$uid] = $item;
            }
        }
        return $missingRelations;
    }
}
