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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use Lolli\Dbdoctor\Tca\ForeignTableListField;
use Lolli\Dbdoctor\Tca\GroupField;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Stateless service detecting relations with a RelationProblem in fields storing their relations as
 * comma separated list in the field itself: Group fields without MM table, and select, category and
 * inline fields without MM table storing uids of their foreign_table. The core RelationHandler reads
 * all of them with readList(), a select field like a group field with its foreign_table as single
 * allowed table.
 */
final readonly class ListRelationHelper
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaHelper $tcaHelper,
        private TableHelper $tableHelper,
        private RelationTargetHelper $relationTargetHelper,
    ) {}

    /**
     * Findings of items in the lists pointing to records with the given problem. They are removed
     * from the list, duplicates too, other items keep their order.
     *
     * With RelationProblem::WorkspaceOverlay, only live records are looked at: Relations of workspace
     * records point to records of their workspace on purpose, the reference index has them as well.
     *
     * @param iterable<GroupField|ForeignTableListField> $listFields
     * @return list<Finding>
     */
    public function getFindings(PreparedStatements $statements, iterable $listFields, RelationProblem $problem): array
    {
        $findings = [];
        foreach ($listFields as $listField) {
            $tableName = $listField->tableName;
            $fieldName = $listField->fieldName;
            if (!$this->tableHelper->fieldExistsInTable($tableName, $fieldName)
                || ($problem === RelationProblem::WorkspaceOverlay && !$this->tcaHelper->hasWorkspaceAwareTargetTable($listField))
            ) {
                continue;
            }
            $isIntegerField = $this->tableHelper->fieldIsInteger($tableName, $fieldName);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records, too.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $fieldName)->from($tableName);
            $typeField = $listField->typeField;
            if ($typeField !== null) {
                // Some record types change the allowed tables of this field, see TcaHelper->getAllowedTablesByType().
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
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            if ($problem === RelationProblem::WorkspaceOverlay && $workspaceIdField !== null) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
            }
            $staticItemValues = $listField instanceof ForeignTableListField ? $listField->staticItemValues : [];
            $result = $queryBuilder->orderBy('uid')->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $row */
                $fieldValue = (string)$row[$fieldName];
                $allowedTables = $this->tcaHelper->getAllowedTablesOfRecordType(
                    $listField,
                    $typeField !== null && $row[$typeField] !== null ? (string)$row[$typeField] : null
                );
                if ($allowedTables === null) {
                    // Relations of this record are ambiguous, they are left alone.
                    continue;
                }
                $relations = $this->getRelationsWithProblem($statements, $problem, $fieldValue, $allowedTables, $staticItemValues);
                if (empty($relations)) {
                    continue;
                }
                $remainingItems = [];
                foreach (GeneralUtility::trimExplode(',', $fieldValue, true) as $item) {
                    if (!in_array($item, $relations, true)) {
                        $remainingItems[] = $item;
                    }
                }
                if (empty($remainingItems) && $isIntegerField) {
                    $newValue = ['value' => 0, 'type' => Connection::PARAM_INT];
                } else {
                    $newValue = ['value' => implode(',', $remainingItems), 'type' => Connection::PARAM_STR];
                }
                $findings[] = new Finding(
                    new RecordFieldKey($tableName, (int)$row['uid'], $fieldName),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], [$fieldName => $newValue])],
                    $this->relationTargetHelper->getReason($problem, $fieldName, array_keys($relations)),
                );
            }
        }
        return $findings;
    }

    /**
     * Parse a comma separated list like core RelationHandler->readList() does, and return the items
     * pointing to a record with the given problem. Items like "tt_content_42" point to a record of the
     * given table, items like "42" point to a record of the first allowed table of the record type.
     * Static items of select fields, items that can not be resolved to an allowed table and a positive
     * uid, and items pointing to a table that does not exist are ignored and kept. The core
     * RelationHandler handles negative values like "-1" of select fields as relation as well, but they
     * can not point to a record.
     *
     * @param array<int, string> $allowedTables
     * @param array<int, string> $staticItemValues
     * @return array<string, string> Key is "tableName:uid", value is the item as stored in the list
     */
    private function getRelationsWithProblem(PreparedStatements $statements, RelationProblem $problem, string $fieldValue, array $allowedTables, array $staticItemValues): array
    {
        $isAnyTableAllowed = in_array('*', $allowedTables, true);
        $firstTable = $isAnyTableAllowed ? '' : ($allowedTables[0] ?? '');
        $relations = [];
        foreach (GeneralUtility::trimExplode(',', $fieldValue, true) as $item) {
            if (in_array($item, $staticItemValues, true)) {
                continue;
            }
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
            ) {
                continue;
            }
            if ($this->relationTargetHelper->hasProblem($statements, $problem, $targetTableName, (int)$uid)) {
                $relations[$targetTableName . ':' . (int)$uid] = $item;
            }
        }
        return $relations;
    }
}
