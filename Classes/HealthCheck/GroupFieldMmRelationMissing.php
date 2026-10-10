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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Relations in TCA type 'group' fields with MM table must point to existing records.
 */
final class GroupFieldMmRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for group fields with MM relations to missing records');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'Fields of TCA type "group" with MM table store their relations as rows in',
            'the MM table, for instance the sys_category field "items". This check finds',
            'MM rows pointing to records that do not exist, and MM rows of local records that',
            'do not exist, and removes them. Relations of and to soft-deleted records are',
            'kept: The backend does not remove them when a record is deleted, and they are',
            'needed when a record is restored using the recycler. The number of relations in',
            'the field of the local record is not updated: dbdoctor ignores these count',
            'fields, see README.md.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $affectedRows = [];
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
                        $affectedRows[$mmTableName][] = $this->getMissingLocalRecordRow($groupField, $currentUidLocal, $mmRowCount);
                    } elseif ($currentUidLocal !== null && !empty($missingRelations)) {
                        $affectedRow = $this->getAffectedRow($run, $groupField, $currentUidLocal, $missingRelations);
                        if ($affectedRow !== null) {
                            $affectedRows[$tableName][] = $affectedRow;
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
                    // Once per DELETE condition: Fields without match fields, like sys_category "items", read
                    // rows of all fields of the opposite side, one DELETE removes all of them.
                    $missingRelations[($tablenames ?? '') . ':' . $uidForeign] = [
                        'tableName' => $targetTableName,
                        'uid_foreign' => $uidForeign,
                        'tablenames' => $tablenames,
                    ];
                }
            }
            if ($currentUidLocal !== null && $isLocalRecordMissing) {
                $affectedRows[$mmTableName][] = $this->getMissingLocalRecordRow($groupField, $currentUidLocal, $mmRowCount);
            } elseif ($currentUidLocal !== null && !empty($missingRelations)) {
                $affectedRow = $this->getAffectedRow($run, $groupField, $currentUidLocal, $missingRelations);
                if ($affectedRow !== null) {
                    $affectedRows[$tableName][] = $affectedRow;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $rowsByMmTable = [];
        foreach ($affectedRecords as $rows) {
            foreach ($rows as $row) {
                $rowsByMmTable[(string)$row['_mmTableName']][] = $row;
            }
        }
        foreach ($rowsByMmTable as $mmTableName => $rows) {
            $this->outputTableDeleteBefore($run, $simulate, $mmTableName);
            $count = 0;
            foreach ($rows as $row) {
                /** @var array<string, int|string> $matchFields */
                $matchFields = json_decode((string)$row['_matchFields'], true, 512, JSON_THROW_ON_ERROR);
                if ((bool)($row['_localRecordMissing'] ?? false)) {
                    $whereFields = [
                        'uid_local' => [
                            'value' => (int)$row['uid'],
                            'type' => Connection::PARAM_INT,
                        ],
                    ];
                    foreach ($matchFields as $matchFieldName => $matchFieldValue) {
                        $whereFields[$matchFieldName] = [
                            'value' => (string)$matchFieldValue,
                            'type' => Connection::PARAM_STR,
                        ];
                    }
                    $this->deleteMmRows($run, $simulate, $mmTableName, $whereFields);
                    $count++;
                    continue;
                }
                /** @var array<int, array{uid_foreign: int, tablenames: string|null}> $missingRelations */
                $missingRelations = json_decode((string)$row['_missingRelations'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($missingRelations as $missingRelation) {
                    $whereFields = [
                        'uid_local' => [
                            'value' => (int)$row['uid'],
                            'type' => Connection::PARAM_INT,
                        ],
                        'uid_foreign' => [
                            'value' => $missingRelation['uid_foreign'],
                            'type' => Connection::PARAM_INT,
                        ],
                    ];
                    if ($missingRelation['tablenames'] !== null) {
                        $whereFields['tablenames'] = [
                            'value' => $missingRelation['tablenames'],
                            'type' => Connection::PARAM_STR,
                        ];
                    }
                    foreach ($matchFields as $matchFieldName => $matchFieldValue) {
                        $whereFields[$matchFieldName] = [
                            'value' => (string)$matchFieldValue,
                            'type' => Connection::PARAM_STR,
                        ];
                    }
                    $this->deleteMmRows($run, $simulate, $mmTableName, $whereFields);
                    $count++;
                }
            }
            $this->outputTableDeleteAfter($run, $simulate, $mmTableName, $count);
        }
    }

    protected function affectedPages(HealthCheckRun $run, array $affectedRecords): void
    {
        // MM rows of missing local records are on no page.
        $this->outputAffectedPages($run, array_filter(
            $affectedRecords,
            static fn(array $rows): bool => !(bool)($rows[0]['_localRecordMissing'] ?? false)
        ));
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            if ((bool)($rows[0]['_localRecordMissing'] ?? false)) {
                // Rows of MM table $tableName, their local record does not exist.
                $run->io->note('MM table "' . $tableName . '":');
                $run->io->table(
                    ['uid_local', 'local table', 'field', 'MM rows'],
                    array_map(
                        static fn(array $row): array => [$row['uid'], $row['_localTableName'], $row['_fieldName'], $row['_mmRowCount']],
                        $rows
                    )
                );
                continue;
            }
            $fieldNames = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['_fieldName'], $rows)));
            $this->outputRecordDetails($run, [$tableName => $rows], '_reasonBroken', [], $fieldNames);
        }
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
     * @param array{tableName: string, fieldName: string, mmTableName: string, allowedTables: array<int, string>, matchFields: array<string, int|string>} $groupField
     * @return array<string, int|string>
     */
    private function getMissingLocalRecordRow(array $groupField, int $uidLocal, int $mmRowCount): array
    {
        return [
            'uid' => $uidLocal,
            'pid' => 0,
            '_localRecordMissing' => 1,
            '_localTableName' => $groupField['tableName'],
            '_fieldName' => $groupField['fieldName'],
            '_mmTableName' => $groupField['mmTableName'],
            '_matchFields' => json_encode($groupField['matchFields'], JSON_THROW_ON_ERROR),
            '_mmRowCount' => $mmRowCount,
        ];
    }

    /**
     * @param array{tableName: string, fieldName: string, mmTableName: string, allowedTables: array<int, string>, matchFields: array<string, int|string>} $groupField
     * @param array<string, array{tableName: string, uid_foreign: int, tablenames: string|null}> $missingRelations
     * @return array<string, int|string>|null
     */
    private function getAffectedRow(HealthCheckRun $run, array $groupField, int $uidLocal, array $missingRelations): ?array
    {
        try {
            $localRecord = $this->recordsHelper->getRecord($run->statements, $groupField['tableName'], ['uid', 'pid'], $uidLocal);
        } catch (NoSuchRecordException $e) {
            // Only if the local side of the MM table is ambiguous, see canHandleMissingLocalRecords():
            // The MM rows are no relation of an existing record of this table, they are kept.
            return null;
        }
        $missingRelationLabels = [];
        $missingRelationRows = [];
        foreach ($missingRelations as $missingRelation) {
            $missingRelationLabels[] = $missingRelation['tableName'] . ':' . $missingRelation['uid_foreign'];
            $missingRelationRows[] = [
                'uid_foreign' => $missingRelation['uid_foreign'],
                'tablenames' => $missingRelation['tablenames'],
            ];
        }
        return [
            'uid' => (int)$localRecord['uid'],
            'pid' => (int)$localRecord['pid'],
            '_fieldName' => $groupField['fieldName'],
            '_mmTableName' => $groupField['mmTableName'],
            '_matchFields' => json_encode($groupField['matchFields'], JSON_THROW_ON_ERROR),
            '_missingRelations' => json_encode($missingRelationRows, JSON_THROW_ON_ERROR),
            '_reasonBroken' => 'Field "' . $groupField['fieldName'] . '": Missing ' . implode(', ', $missingRelationLabels),
        ];
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
