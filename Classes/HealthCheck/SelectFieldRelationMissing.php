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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Relations in TCA type 'select' fields with foreign_table and type 'category' fields
 * without MM table must point to existing records.
 */
final readonly class SelectFieldRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for select fields with relations to missing records',
            [ActionTag::UpdateFields],
            [
                'Fields of TCA type "select" with a "foreign_table" and fields of type "category"',
                'without MM table store their relations as comma separated list or single uid,',
                'for instance the be_users field "usergroup". This check finds relations to records',
                'that do not exist and removes them from the list. Static items of a field, like',
                '"-1" and "-2" of the "fe_group" fields, are no relations and are kept. Relations to',
                'soft-deleted records are kept: The backend does not remove them when a record is',
                'deleted, and they are needed when a record is restored using the recycler.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextSelectFieldWithoutMm() as $selectField) {
            $tableName = $selectField->tableName;
            $fieldName = $selectField->fieldName;
            if ($tableName === 'sys_file_metadata' && $fieldName === 'file') {
                // The earlier check SysFileMetadataFileMissing removes metadata of missing files.
                // Setting the field to 0 would leave a metadata record without file.
                continue;
            }
            if ($tableName === 'sys_file' && $fieldName === 'storage') {
                // Core v13 has a foreign_table for this field. Storage 0 is the core fallback storage
                // for files outside a configured storage: Setting it would move files to that storage.
                continue;
            }
            if (!$this->tableHelper->fieldExistsInTable($tableName, $fieldName)) {
                continue;
            }
            $isIntegerField = $this->tableHelper->fieldIsInteger($tableName, $fieldName);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records, too.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $fieldName)->from($tableName);
            $typeField = $selectField->typeField;
            if ($typeField !== null) {
                // Some record types change "foreign_table" of this field, see TcaHelper->getAllowedTablesByType().
                $queryBuilder->addSelect($typeField);
            }
            if ($isIntegerField) {
                $queryBuilder->where(
                    $queryBuilder->expr()->gt($fieldName, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
            } else {
                $queryBuilder->where(
                    $queryBuilder->expr()->neq($fieldName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull($fieldName)
                );
            }
            $result = $queryBuilder->orderBy('uid')->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $row */
                $fieldValue = (string)$row[$fieldName];
                $allowedTables = $this->tcaHelper->getAllowedTablesOfRecordType(
                    $selectField,
                    $typeField !== null && $row[$typeField] !== null ? (string)$row[$typeField] : null
                );
                if ($allowedTables === null || !isset($allowedTables[0])) {
                    // Relations of this record are ambiguous, they are left alone.
                    continue;
                }
                $missingRelations = $this->getMissingRelations($run, $fieldValue, $allowedTables[0], $selectField->staticItemValues);
                if (empty($missingRelations)) {
                    continue;
                }
                // All items pointing to a missing record are removed, duplicates too, others keep their order.
                $remainingItems = [];
                foreach (GeneralUtility::trimExplode(',', $fieldValue, true) as $item) {
                    if (!in_array($item, $missingRelations, true)) {
                        $remainingItems[] = $item;
                    }
                }
                if (empty($remainingItems) && $isIntegerField) {
                    $newValue = ['value' => 0, 'type' => Connection::PARAM_INT];
                } else {
                    $newValue = ['value' => implode(',', $remainingItems), 'type' => Connection::PARAM_STR];
                }
                $missing = array_keys($missingRelations);
                $findings[] = new Finding(
                    new RecordFieldKey($tableName, (int)$row['uid'], $fieldName),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], [$fieldName => $newValue])],
                    new Reason(
                        'relation-missing',
                        'Field "' . $fieldName . '": Missing ' . implode(', ', $missing),
                        ['missing' => $missing]
                    ),
                );
            }
        }
        return $findings;
    }

    /**
     * Parse a comma separated list of a select field like core RelationHandler->readList() does
     * with a single table, and return the items that point to a not existing record. Items like
     * "42" or "fe_groups_42" point to a record of the foreign table. Static items, items that
     * are no positive uid, and items with another table name are ignored and kept. The core
     * RelationHandler handles negative values like "-1" as relation as well, but they can not
     * point to a record.
     *
     * @param array<int, string> $staticItemValues
     * @return array<string, string> Key is "tableName:uid", value is the item as stored in the list
     */
    private function getMissingRelations(HealthCheckRun $run, string $fieldValue, string $foreignTable, array $staticItemValues): array
    {
        if (!is_array($GLOBALS['TCA'][$foreignTable] ?? false)
            || !$this->tableHelper->tableExistsInDatabase($foreignTable)
        ) {
            return [];
        }
        $missingRelations = [];
        foreach (GeneralUtility::trimExplode(',', $fieldValue, true) as $item) {
            if (in_array($item, $staticItemValues, true)) {
                continue;
            }
            // "fe_groups_42": Split at last underscore. Table names may contain underscores.
            $parts = explode('_', strrev($item), 2);
            $uid = strrev($parts[0]);
            if (!MathUtility::canBeInterpretedAsInteger($uid) || (int)$uid <= 0) {
                continue;
            }
            if (($parts[1] ?? '') !== '' && strrev($parts[1]) !== $foreignTable) {
                continue;
            }
            try {
                $this->recordsHelper->getRecord($run->statements, $foreignTable, ['uid'], (int)$uid);
            } catch (NoSuchRecordException $e) {
                $missingRelations[$foreignTable . ':' . (int)$uid] = $item;
            }
        }
        return $missingRelations;
    }
}
