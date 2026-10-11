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
 * Relations in TCA type 'group' fields without MM table must point to existing records.
 */
final readonly class GroupFieldRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for group fields with relations to missing records',
            [self::TAG_UPDATE],
            [
                'Fields of TCA type "group" without MM table store their relations as comma',
                'separated list, for instance the tt_content field "records" of content type',
                '"Insert records". This check finds relations to records that do not exist',
                'and removes them from the list. Relations to soft-deleted records are kept:',
                'The backend does not remove them when a record is deleted, and they are',
                'needed when a record is restored using the recycler.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextGroupFieldWithoutMm() as $groupField) {
            $tableName = $groupField['tableName'];
            $fieldName = $groupField['fieldName'];
            if ($tableName === 'sys_file_reference' && $fieldName === 'uid_local') {
                // The earlier check SysFileReferenceDangling removes file references to missing files.
                // Checking them again here costs one query per file reference, and emptying uid_local
                // would leave a broken reference. If SysFileReferenceDangling is disabled, they are kept.
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
            if ($isIntegerField) {
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
                $fieldValue = (string)$row[$fieldName];
                $missingRelations = $this->getMissingRelations($run, $fieldValue, $groupField['allowedTables']);
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
     * Parse a comma separated list of a group field like core RelationHandler->readList() does,
     * and return the items that point to a not existing record. Items like "tt_content_42"
     * point to a record of the given table, items like "42" point to a record of the first
     * allowed table. Items that can not be resolved to an allowed table and a positive uid,
     * or that point to a table that does not exist, are ignored and kept.
     *
     * @param array<int, string> $allowedTables
     * @return array<string, string> Key is "tableName:uid", value is the item as stored in the list
     */
    private function getMissingRelations(HealthCheckRun $run, string $fieldValue, array $allowedTables): array
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
                || !$this->tableHelper->tableExistsInDatabase($targetTableName)
            ) {
                continue;
            }
            try {
                $this->recordsHelper->getRecord($run->statements, $targetTableName, ['uid'], (int)$uid);
            } catch (NoSuchRecordException $e) {
                $missingRelations[$targetTableName . ':' . (int)$uid] = $item;
            }
        }
        return $missingRelations;
    }
}
