<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

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
use Lolli\Dbdoctor\HealthCheck\HealthCheckRun;
use Lolli\Dbdoctor\Tca\ForeignTableListField;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Stateless service detecting relations to missing records in fields storing uids of their
 * foreign_table as comma separated list, for the checks of select, category and inline fields
 * without MM table. The core RelationHandler reads all of them as list of a single table.
 */
final readonly class ForeignTableListRelationHelper
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaHelper $tcaHelper,
        private RecordsHelper $recordsHelper,
        private TableHelper $tableHelper,
    ) {}

    /**
     * Findings of uids in the lists pointing to missing records.
     *
     * @param iterable<ForeignTableListField> $listFields
     * @return list<Finding>
     */
    public function getFindings(HealthCheckRun $run, iterable $listFields): array
    {
        $findings = [];
        foreach ($listFields as $listField) {
            $tableName = $listField->tableName;
            $fieldName = $listField->fieldName;
            if (!$this->tableHelper->fieldExistsInTable($tableName, $fieldName)) {
                continue;
            }
            $isIntegerField = $this->tableHelper->fieldIsInteger($tableName, $fieldName);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records, too.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $fieldName)->from($tableName);
            $typeField = $listField->typeField;
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
                    $listField,
                    $typeField !== null && $row[$typeField] !== null ? (string)$row[$typeField] : null
                );
                if ($allowedTables === null || !isset($allowedTables[0])) {
                    // Relations of this record are ambiguous, they are left alone.
                    continue;
                }
                $missingRelations = $this->getMissingRelations($run, $fieldValue, $allowedTables[0], $listField->staticItemValues);
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
     * Parse a comma separated list of a field like core RelationHandler->readList() does
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
