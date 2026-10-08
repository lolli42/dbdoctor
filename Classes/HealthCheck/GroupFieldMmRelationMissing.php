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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
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
        $this->outputTags($io, self::TAG_REMOVE, self::TAG_UPDATE);
        $io->text([
            'Fields of TCA type "group" with MM table store their relations as rows in',
            'the MM table, for instance the sys_category field "items". This check finds',
            'MM rows pointing to records that do not exist, removes them, and updates the',
            'number of relations in the field of the local record. Relations to',
            'soft-deleted records are kept: The backend does not remove them when a',
            'record is deleted, and they are needed when a record is restored using the',
            'recycler.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextGroupFieldWithMm() as $groupField) {
            $tableName = $groupField['tableName'];
            $fieldName = $groupField['fieldName'];
            $mmTableName = $groupField['mmTableName'];
            if (!$tableHelper->fieldExistsInTable($tableName, $fieldName)
                || !$tableHelper->fieldExistsInTable($mmTableName, 'uid_local')
                || !$tableHelper->fieldExistsInTable($mmTableName, 'uid_foreign')
            ) {
                continue;
            }
            foreach (array_keys($groupField['matchFields']) as $matchFieldName) {
                if (!$tableHelper->fieldExistsInTable($mmTableName, $matchFieldName)) {
                    continue 2;
                }
            }
            $hasTablenamesField = $tableHelper->fieldExistsInTable($mmTableName, 'tablenames');
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
            // MM rows are sorted by uid_local: Collect relations of one local record,
            // and handle them when the next local record starts.
            $currentUidLocal = null;
            $numberOfRelations = 0;
            $missingRelations = [];
            while ($mmRow = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $mmRow */
                if ($currentUidLocal !== (int)$mmRow['uid_local']) {
                    if ($currentUidLocal !== null && !empty($missingRelations)) {
                        $affectedRow = $this->getAffectedRow($recordsHelper, $groupField, $currentUidLocal, $numberOfRelations, $missingRelations);
                        if ($affectedRow !== null) {
                            $affectedRows[$tableName][] = $affectedRow;
                        }
                    }
                    $currentUidLocal = (int)$mmRow['uid_local'];
                    $numberOfRelations = 0;
                    $missingRelations = [];
                }
                $tablenames = $hasTablenamesField ? (string)$mmRow['tablenames'] : null;
                $uidForeign = (int)$mmRow['uid_foreign'];
                $targetTableName = $this->getTargetTableName($tablenames, $groupField['allowedTables']);
                if ($targetTableName === '' || ($uidForeign === 0 && $targetTableName !== 'pages')) {
                    // Not a relation of this field, as in core RelationHandler->readMM().
                    continue;
                }
                $numberOfRelations++;
                if ($uidForeign > 0 && $this->isRecordMissing($recordsHelper, $tableHelper, $targetTableName, $uidForeign)) {
                    $missingRelations[] = [
                        'tableName' => $targetTableName,
                        'uid_foreign' => $uidForeign,
                        'tablenames' => $tablenames,
                    ];
                }
            }
            if ($currentUidLocal !== null && !empty($missingRelations)) {
                $affectedRow = $this->getAffectedRow($recordsHelper, $groupField, $currentUidLocal, $numberOfRelations, $missingRelations);
                if ($affectedRow !== null) {
                    $affectedRows[$tableName][] = $affectedRow;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $rows) {
            $this->outputTableUpdateBefore($io, $simulate, $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $mmTableName = (string)$row['_mmTableName'];
                /** @var array<string, int|string> $matchFields */
                $matchFields = json_decode((string)$row['_matchFields'], true, 512, JSON_THROW_ON_ERROR);
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
                    $this->deleteMmRows($io, $simulate, $recordsHelper, $mmTableName, $whereFields);
                }
                $updateFields = [
                    (string)$row['_fieldName'] => [
                        'value' => (int)$row['_numberOfRemainingRelations'],
                        'type' => Connection::PARAM_INT,
                    ],
                ];
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
     * @param array{tableName: string, fieldName: string, mmTableName: string, allowedTables: array<int, string>, matchFields: array<string, int|string>} $groupField
     * @param array<int, array{tableName: string, uid_foreign: int, tablenames: string|null}> $missingRelations
     * @return array<string, int|string>|null
     */
    private function getAffectedRow(RecordsHelper $recordsHelper, array $groupField, int $uidLocal, int $numberOfRelations, array $missingRelations): ?array
    {
        try {
            $localRecord = $recordsHelper->getRecord($groupField['tableName'], ['uid', 'pid'], $uidLocal);
        } catch (NoSuchRecordException $e) {
            // MM rows of a missing local record are not a relation of an existing record.
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
            '_numberOfRemainingRelations' => $numberOfRelations - count($missingRelations),
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

    private function isRecordMissing(RecordsHelper $recordsHelper, TableHelper $tableHelper, string $tableName, int $uid): bool
    {
        if (!$tableHelper->tableExistsInDatabase($tableName)) {
            // Not our business, the relation can not be checked.
            return false;
        }
        try {
            $recordsHelper->getRecord($tableName, ['uid'], $uid);
        } catch (NoSuchRecordException $e) {
            return true;
        }
        return false;
    }
}
