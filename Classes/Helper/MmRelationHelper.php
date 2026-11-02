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
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\FieldValue;
use Lolli\Dbdoctor\Finding\FieldValueList;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\MmKey;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use Lolli\Dbdoctor\Tca\MmField;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stateless service detecting MM relations with a RelationProblem, for the checks of
 * group, select and inline fields with MM table. The core RelationHandler reads and
 * writes the MM rows of all three the same way.
 */
final readonly class MmRelationHelper
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaHelper $tcaHelper,
        private RecordsHelper $recordsHelper,
        private TableHelper $tableHelper,
        private RelationTargetHelper $relationTargetHelper,
    ) {}

    /**
     * Findings of MM rows pointing to records with the given problem. With RelationProblem::Missing
     * also MM rows of missing local records. With RelationProblem::WorkspaceOverlay, only MM rows of
     * live local records are looked at: The core writes MM rows of workspace records pointing to
     * records of their workspace on purpose. MM rows pointing to an overlay of a table editing the
     * relation from the opposite side are skipped as well, see hasOppositeField().
     *
     * @param iterable<MmField> $mmFields
     * @return list<Finding>
     */
    public function getFindings(PreparedStatements $statements, iterable $mmFields, RelationProblem $problem): array
    {
        // Findings per table they are counted for: The local table, or the MM table for MM rows of missing local records.
        $findingsByTable = [];
        foreach ($mmFields as $mmField) {
            $tableName = $mmField->tableName;
            $fieldName = $mmField->fieldName;
            $mmTableName = $mmField->mmTableName;
            if (!$this->tableHelper->fieldExistsInTable($tableName, $fieldName)
                || !$this->tableHelper->fieldExistsInTable($mmTableName, 'uid_local')
                || !$this->tableHelper->fieldExistsInTable($mmTableName, 'uid_foreign')
                || ($problem === RelationProblem::WorkspaceOverlay && !$this->tcaHelper->hasWorkspaceAwareTargetTable($mmField))
            ) {
                continue;
            }
            foreach (array_keys($mmField->matchFields) as $matchFieldName) {
                if (!$this->tableHelper->fieldExistsInTable($mmTableName, $matchFieldName)) {
                    continue 2;
                }
            }
            if (empty($mmField->matchFields) && count($this->getLocalFieldsOfMmTable($mmTableName)) > 1) {
                // Broken TCA, ignored: A field without match fields sharing its MM table with other fields
                // reads the rows of these fields as its own. Core RelationHandler is broken with this as well,
                // readMM() and writeMM() restrict queries by match fields only. Without a "tablenames" column,
                // their uid_foreign would be checked against the wrong table, and relations found missing by
                // multiple fields would be removed more than once.
                continue;
            }
            $hasTablenamesField = $this->tableHelper->fieldExistsInTable($mmTableName, 'tablenames');
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTableName);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('mm.uid_local AS uid_local', 'mm.uid_foreign AS uid_foreign')->from($mmTableName, 'mm');
            if ($hasTablenamesField) {
                $queryBuilder->addSelect('mm.tablenames AS tablenames');
            }
            $typeField = $mmField->typeField;
            $localWorkspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isLiveLocalRecordRequired = $problem === RelationProblem::WorkspaceOverlay && $localWorkspaceIdField !== null;
            if ($typeField !== null) {
                // Some record types change the allowed tables of this field, see TcaHelper->getAllowedTablesByType().
                // The type of the local record is joined, instead of a query per local record.
                $queryBuilder->addSelect('local.' . $typeField . ' AS local_record_type');
            }
            if ($isLiveLocalRecordRequired) {
                $queryBuilder->join(
                    'mm',
                    $tableName,
                    'local',
                    (string)$queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('local.uid', $queryBuilder->quoteIdentifier('mm.uid_local')),
                        $queryBuilder->expr()->eq('local.' . $localWorkspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                    )
                );
            } elseif ($typeField !== null) {
                $queryBuilder->leftJoin('mm', $tableName, 'local', $queryBuilder->expr()->eq('local.uid', $queryBuilder->quoteIdentifier('mm.uid_local')));
            }
            foreach ($mmField->matchFields as $matchFieldName => $matchFieldValue) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq('mm.' . $matchFieldName, $queryBuilder->createNamedParameter((string)$matchFieldValue))
                );
            }
            $result = $queryBuilder->orderBy('mm.uid_local')->addOrderBy('mm.uid_foreign')->executeQuery();
            $canHandleMissingLocalRecords = $problem === RelationProblem::Missing && $this->canHandleMissingLocalRecords($mmTableName);
            // MM rows are sorted by uid_local: Collect relations of one local record,
            // and handle them when the next local record starts.
            $currentUidLocal = null;
            $isLocalRecordMissing = false;
            $mmRowCount = 0;
            $relations = [];
            while ($mmRow = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $mmRow */
                if ($currentUidLocal !== (int)$mmRow['uid_local']) {
                    if ($currentUidLocal !== null && $isLocalRecordMissing) {
                        $findingsByTable[$mmTableName][] = $this->getMissingLocalRecordFinding($mmField, $currentUidLocal, $mmRowCount);
                    } elseif ($currentUidLocal !== null && !empty($relations)) {
                        $finding = $this->getRelationsFinding($statements, $problem, $mmField, $currentUidLocal, $relations);
                        if ($finding !== null) {
                            $findingsByTable[$tableName][] = $finding;
                        }
                    }
                    $currentUidLocal = (int)$mmRow['uid_local'];
                    $isLocalRecordMissing = $canHandleMissingLocalRecords
                        && $this->relationTargetHelper->hasProblem($statements, RelationProblem::Missing, $tableName, $currentUidLocal);
                    $mmRowCount = 0;
                    $relations = [];
                }
                if ($isLocalRecordMissing) {
                    // All MM rows of a missing local record are removed, foreign records do not matter.
                    $mmRowCount++;
                    continue;
                }
                $allowedTables = $this->tcaHelper->getAllowedTablesOfRecordType(
                    $mmField,
                    $typeField !== null && $mmRow['local_record_type'] !== null ? (string)$mmRow['local_record_type'] : null
                );
                if ($allowedTables === null) {
                    // Relations of this record are ambiguous, they are left alone.
                    continue;
                }
                $tablenames = $hasTablenamesField ? (string)$mmRow['tablenames'] : null;
                $uidForeign = (int)$mmRow['uid_foreign'];
                $targetTableName = $this->getTargetTableName($tablenames, $allowedTables);
                if ($targetTableName === '') {
                    // Not a relation of this field, as in core RelationHandler->readMM().
                    continue;
                }
                if ($problem === RelationProblem::WorkspaceOverlay && $this->hasOppositeField($mmTableName, $targetTableName)) {
                    // The target table edits the relation from the opposite side, like the "categories" field of pages
                    // for sys_category "items": A workspace overlay of the target owns the MM rows pointing to it.
                    continue;
                }
                if ($uidForeign > 0 && $this->relationTargetHelper->hasProblem($statements, $problem, $targetTableName, $uidForeign)) {
                    // Grouped by "tablenames", a DELETE condition of its own. Once per uid_foreign: Fields without
                    // match fields, like sys_category "items", read rows of all fields of the opposite side, one
                    // DELETE removes all of them.
                    $relations[$tablenames ?? ''] ??= [
                        'tableName' => $targetTableName,
                        'tablenames' => $tablenames,
                        'uids' => [],
                    ];
                    $relations[$tablenames ?? '']['uids'][$uidForeign] = $uidForeign;
                }
            }
            if ($currentUidLocal !== null && $isLocalRecordMissing) {
                $findingsByTable[$mmTableName][] = $this->getMissingLocalRecordFinding($mmField, $currentUidLocal, $mmRowCount);
            } elseif ($currentUidLocal !== null && !empty($relations)) {
                $finding = $this->getRelationsFinding($statements, $problem, $mmField, $currentUidLocal, $relations);
                if ($finding !== null) {
                    $findingsByTable[$tableName][] = $finding;
                }
            }
        }
        // Changes are done per MM table: Keep findings of one MM table together, in order of appearance.
        $findingsByMmTable = [];
        foreach ($findingsByTable as $findings) {
            foreach ($findings as $finding) {
                $findingsByMmTable[$finding->changes[0]->tableName][] = $finding;
            }
        }
        return array_merge(...array_values($findingsByMmTable));
    }

    /**
     * True if the table has a field using this MM table from the opposite side (MM_opposite_field). Such
     * relations can be edited from both sides: The DataHandler writes the MM rows of a workspace overlay of
     * the opposite side with the live record on the local side and the overlay in uid_foreign. These rows
     * belong to the workspace, the reference index adds them for the workspace (core 13.4 and 14.3).
     */
    private function hasOppositeField(string $mmTableName, string $tableName): bool
    {
        foreach (($GLOBALS['TCA'][$tableName]['columns'] ?? []) as $columnConfig) {
            if (($columnConfig['config']['MM'] ?? '') === $mmTableName
                && !empty($columnConfig['config']['MM_opposite_field'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * MM rows of a local record that does not exist are no relation of any record and
     * can be removed. Only if the local side is unambiguous: All TCA fields using this
     * MM table as local side are in the same table, and if there are multiple fields,
     * all have match fields. Otherwise, uid_local may point to a record of another table,
     * or removing rows of one field would remove rows of another field, too.
     */
    private function canHandleMissingLocalRecords(string $mmTableName): bool
    {
        $localFields = $this->getLocalFieldsOfMmTable($mmTableName);
        $localTableNames = array_unique(array_column($localFields, 'tableName'));
        $fieldsWithoutMatchFields = count(array_filter($localFields, static fn(array $localField): bool => !$localField['hasMatchFields']));
        return count($localTableNames) === 1 && (count($localFields) === 1 || $fieldsWithoutMatchFields === 0);
    }

    /**
     * All TCA fields using this MM table as local side.
     *
     * @return array<int, array{tableName: string, hasMatchFields: bool}>
     */
    private function getLocalFieldsOfMmTable(string $mmTableName): array
    {
        $localFields = [];
        foreach ($GLOBALS['TCA'] as $tableName => $tableConfig) {
            foreach ($tableConfig['columns'] ?? [] as $columnConfig) {
                if (($columnConfig['config']['MM'] ?? '') !== $mmTableName
                    || !empty($columnConfig['config']['MM_opposite_field'])
                ) {
                    continue;
                }
                $localFields[] = [
                    'tableName' => (string)$tableName,
                    'hasMatchFields' => !empty($columnConfig['config']['MM_match_fields']),
                ];
            }
        }
        return $localFields;
    }

    /**
     * All MM rows of the missing local record within the match fields of the field are removed.
     */
    private function getMissingLocalRecordFinding(MmField $mmField, int $uidLocal, int $mmRowCount): Finding
    {
        $where = [
            'uid_local' => new FieldValue($uidLocal, Connection::PARAM_INT),
        ];
        $where = array_merge($where, $this->getMatchFieldsWhere($mmField->matchFields));
        return new Finding(
            new MmKey($mmField->mmTableName, $uidLocal, $mmField->tableName, $mmField->fieldName, $mmField->matchFields),
            null,
            [new RecordChange(RecordChangeType::MmDelete, $mmField->mmTableName, null, [], $where)],
            new Reason(
                'local-record-missing',
                'Local record "' . $mmField->tableName . ':' . $uidLocal . '" does not exist',
                ['localRecord' => $mmField->tableName . ':' . $uidLocal]
            ),
            ['mmRowCount' => $mmRowCount],
        );
    }

    /**
     * One DELETE per up to 100 relations with the problem to one table: Many relations, for
     * instance of a category assigned to many records deleted meanwhile, need few changes and
     * little memory, and IN() stays below the limits of bound parameters of the platforms.
     * A DELETE may remove more MM rows than relations: Duplicates, and rows of other fields
     * of the opposite side for fields without match fields.
     *
     * @param array<string, array{tableName: string, tablenames: string|null, uids: array<int, int>}> $relations
     */
    private function getRelationsFinding(PreparedStatements $statements, RelationProblem $problem, MmField $mmField, int $uidLocal, array $relations): ?Finding
    {
        try {
            $localRecord = $this->recordsHelper->getRecord($statements, $mmField->tableName, ['uid', 'pid'], $uidLocal);
        } catch (NoSuchRecordException $e) {
            // Only if the local side of the MM table is ambiguous, see canHandleMissingLocalRecords():
            // The MM rows are no relation of an existing record of this table, they are kept.
            return null;
        }
        $missing = [];
        $changes = [];
        foreach ($relations as $missingRelation) {
            foreach ($missingRelation['uids'] as $uidForeign) {
                $missing[] = $missingRelation['tableName'] . ':' . $uidForeign;
            }
            foreach (array_chunk(array_values($missingRelation['uids']), 100) as $uidsForeign) {
                $where = [
                    'uid_local' => new FieldValue($uidLocal, Connection::PARAM_INT),
                    'uid_foreign' => new FieldValueList($uidsForeign, Connection::PARAM_INT),
                ];
                if ($missingRelation['tablenames'] !== null) {
                    $where['tablenames'] = new FieldValue($missingRelation['tablenames'], Connection::PARAM_STR);
                }
                $where = array_merge($where, $this->getMatchFieldsWhere($mmField->matchFields));
                $changes[] = new RecordChange(RecordChangeType::MmDelete, $mmField->mmTableName, null, [], $where);
            }
        }
        return new Finding(
            new RecordFieldKey($mmField->tableName, (int)$localRecord['uid'], $mmField->fieldName),
            (int)$localRecord['pid'],
            $changes,
            $this->relationTargetHelper->getReason($problem, $mmField->fieldName, $missing),
        );
    }

    /**
     * @param array<string, int|string> $matchFields
     * @return array<string, FieldValue>
     */
    private function getMatchFieldsWhere(array $matchFields): array
    {
        $where = [];
        foreach ($matchFields as $matchFieldName => $matchFieldValue) {
            $where[$matchFieldName] = new FieldValue((string)$matchFieldValue, Connection::PARAM_STR);
        }
        return $where;
    }

    /**
     * Table of an MM row, as in core RelationHandler->readMM(): "tablenames" if set, else
     * the first allowed table of the record type. Empty string if the table is not allowed.
     *
     * @param array<int, string> $allowedTables
     */
    private function getTargetTableName(?string $tablenames, array $allowedTables): string
    {
        $isAnyTableAllowed = in_array('*', $allowedTables, true);
        if ($tablenames !== null && $tablenames !== '') {
            $targetTableName = $tablenames;
        } elseif (!$isAnyTableAllowed) {
            $targetTableName = $allowedTables[0] ?? '';
        } else {
            return '';
        }
        if ((!$isAnyTableAllowed && !in_array($targetTableName, $allowedTables, true))
            || !is_array($GLOBALS['TCA'][$targetTableName] ?? false)
        ) {
            return '';
        }
        return $targetTableName;
    }
}
