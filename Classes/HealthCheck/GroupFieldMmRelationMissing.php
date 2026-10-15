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

use Doctrine\DBAL\ParameterType;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\MmKey;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Relations in TCA type 'group' fields with MM table must point to existing records.
 */
final readonly class GroupFieldMmRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for group fields with MM relations to missing records',
            [ActionTag::Remove],
            [
                'Fields of TCA type "group" with MM table store their relations as rows in',
                'the MM table, for instance the sys_category field "items". This check finds',
                'MM rows pointing to records that do not exist, and MM rows of local records that',
                'do not exist, and removes them. Relations of and to soft-deleted records are',
                'kept: The backend does not remove them when a record is deleted, and they are',
                'needed when a record is restored using the recycler. The number of relations in',
                'the field of the local record is not updated: dbdoctor ignores these count',
                'fields, see README.md.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        // Findings per table they are counted for: The local table, or the MM table for MM rows of missing local records.
        $findingsByTable = [];
        foreach ($this->tcaHelper->getNextGroupFieldWithMm() as $groupField) {
            $tableName = $groupField['tableName'];
            $fieldName = $groupField['fieldName'];
            $mmTableName = $groupField['mmTableName'];
            if (!$this->tableHelper->fieldExistsInTable($tableName, $fieldName)
                || !$this->tableHelper->fieldExistsInTable($mmTableName, 'uid_local')
                || !$this->tableHelper->fieldExistsInTable($mmTableName, 'uid_foreign')
            ) {
                continue;
            }
            foreach (array_keys($groupField['matchFields']) as $matchFieldName) {
                if (!$this->tableHelper->fieldExistsInTable($mmTableName, $matchFieldName)) {
                    continue 2;
                }
            }
            if (empty($groupField['matchFields']) && count($this->getLocalFieldsOfMmTable($mmTableName)) > 1) {
                // Broken TCA, ignored: A field without match fields sharing its MM table with other fields
                // reads the rows of these fields as its own. Core RelationHandler is broken with this as well,
                // readMM() and writeMM() restrict queries by match fields only. Without a "tablenames" column,
                // their uid_foreign would be checked against the wrong table, and relations found missing by
                // multiple fields would be removed more than once.
                continue;
            }
            $hasTablenamesField = $this->tableHelper->fieldExistsInTable($mmTableName, 'tablenames');
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTableName);
            $queryBuilder->select('uid_local', 'uid_foreign')->from($mmTableName);
            if ($hasTablenamesField) {
                $queryBuilder->addSelect('tablenames');
            }
            foreach ($groupField['matchFields'] as $matchFieldName => $matchFieldValue) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($matchFieldName, $queryBuilder->createNamedParameter((string)$matchFieldValue))
                );
            }
            $result = $queryBuilder->orderBy('uid_local')->addOrderBy('uid_foreign')->executeQuery();
            $canHandleMissingLocalRecords = $this->canHandleMissingLocalRecords($mmTableName);
            // MM rows are sorted by uid_local: Collect relations of one local record,
            // and handle them when the next local record starts.
            $currentUidLocal = null;
            $isLocalRecordMissing = false;
            $mmRowCount = 0;
            $missingRelations = [];
            while ($mmRow = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $mmRow */
                if ($currentUidLocal !== (int)$mmRow['uid_local']) {
                    if ($currentUidLocal !== null && $isLocalRecordMissing) {
                        $findingsByTable[$mmTableName][] = $this->getMissingLocalRecordFinding($groupField, $currentUidLocal, $mmRowCount);
                    } elseif ($currentUidLocal !== null && !empty($missingRelations)) {
                        $finding = $this->getMissingRelationsFinding($run, $groupField, $currentUidLocal, $missingRelations);
                        if ($finding !== null) {
                            $findingsByTable[$tableName][] = $finding;
                        }
                    }
                    $currentUidLocal = (int)$mmRow['uid_local'];
                    $isLocalRecordMissing = $canHandleMissingLocalRecords
                        && $this->isRecordMissing($run, $tableName, $currentUidLocal);
                    $mmRowCount = 0;
                    $missingRelations = [];
                }
                if ($isLocalRecordMissing) {
                    // All MM rows of a missing local record are removed, foreign records do not matter.
                    $mmRowCount++;
                    continue;
                }
                $tablenames = $hasTablenamesField ? (string)$mmRow['tablenames'] : null;
                $uidForeign = (int)$mmRow['uid_foreign'];
                $targetTableName = $this->getTargetTableName($tablenames, $groupField['allowedTables']);
                if ($targetTableName === '') {
                    // Not a relation of this field, as in core RelationHandler->readMM().
                    continue;
                }
                if ($uidForeign > 0 && $this->isRecordMissing($run, $targetTableName, $uidForeign)) {
                    // Grouped by "tablenames", a DELETE condition of its own. Once per uid_foreign: Fields without
                    // match fields, like sys_category "items", read rows of all fields of the opposite side, one
                    // DELETE removes all of them.
                    $missingRelations[$tablenames ?? ''] ??= [
                        'tableName' => $targetTableName,
                        'tablenames' => $tablenames,
                        'uids' => [],
                    ];
                    $missingRelations[$tablenames ?? '']['uids'][$uidForeign] = $uidForeign;
                }
            }
            if ($currentUidLocal !== null && $isLocalRecordMissing) {
                $findingsByTable[$mmTableName][] = $this->getMissingLocalRecordFinding($groupField, $currentUidLocal, $mmRowCount);
            } elseif ($currentUidLocal !== null && !empty($missingRelations)) {
                $finding = $this->getMissingRelationsFinding($run, $groupField, $currentUidLocal, $missingRelations);
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
     *
     * @param array{tableName: string, fieldName: string, mmTableName: string, allowedTables: array<int, string>, matchFields: array<string, int|string>} $groupField
     */
    private function getMissingLocalRecordFinding(array $groupField, int $uidLocal, int $mmRowCount): Finding
    {
        $where = [
            'uid_local' => [
                'value' => $uidLocal,
                'type' => Connection::PARAM_INT,
            ],
        ];
        $where = array_merge($where, $this->getMatchFieldsWhere($groupField['matchFields']));
        return new Finding(
            new MmKey($groupField['mmTableName'], $uidLocal, $groupField['tableName'], $groupField['fieldName'], $groupField['matchFields']),
            null,
            [new RecordChange(RecordChangeType::MmDelete, $groupField['mmTableName'], null, [], $where)],
            new Reason(
                'local-record-missing',
                'Local record "' . $groupField['tableName'] . ':' . $uidLocal . '" does not exist',
                ['localRecord' => $groupField['tableName'] . ':' . $uidLocal]
            ),
            ['mmRowCount' => $mmRowCount],
        );
    }

    /**
     * One DELETE per up to 100 missing relations to one table: Many missing relations, for
     * instance of a category assigned to many records deleted meanwhile, need few changes and
     * little memory, and IN() stays below the limits of bound parameters of the platforms.
     * A DELETE may remove more MM rows than relations: Duplicates, and rows of other fields
     * of the opposite side for fields without match fields.
     *
     * @param array{tableName: string, fieldName: string, mmTableName: string, allowedTables: array<int, string>, matchFields: array<string, int|string>} $groupField
     * @param array<string, array{tableName: string, tablenames: string|null, uids: array<int, int>}> $missingRelations
     */
    private function getMissingRelationsFinding(HealthCheckRun $run, array $groupField, int $uidLocal, array $missingRelations): ?Finding
    {
        try {
            $localRecord = $this->recordsHelper->getRecord($run->statements, $groupField['tableName'], ['uid', 'pid'], $uidLocal);
        } catch (NoSuchRecordException $e) {
            // Only if the local side of the MM table is ambiguous, see canHandleMissingLocalRecords():
            // The MM rows are no relation of an existing record of this table, they are kept.
            return null;
        }
        $missing = [];
        $changes = [];
        foreach ($missingRelations as $missingRelation) {
            foreach ($missingRelation['uids'] as $uidForeign) {
                $missing[] = $missingRelation['tableName'] . ':' . $uidForeign;
            }
            foreach (array_chunk(array_values($missingRelation['uids']), 100) as $uidsForeign) {
                $where = [
                    'uid_local' => [
                        'value' => $uidLocal,
                        'type' => Connection::PARAM_INT,
                    ],
                    'uid_foreign' => [
                        'value' => $uidsForeign,
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                if ($missingRelation['tablenames'] !== null) {
                    $where['tablenames'] = [
                        'value' => $missingRelation['tablenames'],
                        'type' => Connection::PARAM_STR,
                    ];
                }
                $where = array_merge($where, $this->getMatchFieldsWhere($groupField['matchFields']));
                $changes[] = new RecordChange(RecordChangeType::MmDelete, $groupField['mmTableName'], null, [], $where);
            }
        }
        return new Finding(
            new RecordFieldKey($groupField['tableName'], (int)$localRecord['uid'], $groupField['fieldName']),
            (int)$localRecord['pid'],
            $changes,
            new Reason(
                'relation-missing',
                'Field "' . $groupField['fieldName'] . '": Missing ' . implode(', ', $missing),
                ['missing' => $missing]
            ),
        );
    }

    /**
     * @param array<string, int|string> $matchFields
     * @return array<string, array{value: string, type: ParameterType}>
     */
    private function getMatchFieldsWhere(array $matchFields): array
    {
        $where = [];
        foreach ($matchFields as $matchFieldName => $matchFieldValue) {
            $where[$matchFieldName] = [
                'value' => (string)$matchFieldValue,
                'type' => Connection::PARAM_STR,
            ];
        }
        return $where;
    }

    /**
     * Table of an MM row, as in core RelationHandler->readMM(): "tablenames" if
     * set, else the first allowed table. Empty string if the table is not allowed.
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

    private function isRecordMissing(HealthCheckRun $run, string $tableName, int $uid): bool
    {
        if (!$this->tableHelper->tableExistsInDatabase($tableName)) {
            // Not our business, the relation can not be checked.
            return false;
        }
        try {
            $this->recordsHelper->getRecord($run->statements, $tableName, ['uid'], $uid);
        } catch (NoSuchRecordException $e) {
            return true;
        }
        return false;
    }
}
