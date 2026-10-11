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
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field without TCA foreign_table_field children
 * that are not deleted but parent is deleted.
 */
final readonly class InlineForeignFieldNoForeignTableFieldChildrenParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline foreign field records with deleted=1 parent',
            [ActionTag::SoftDelete, ActionTag::WorkspaceRemove],
            [
                'TCA inline foreign field records point to a parent record. When this parent is',
                'soft-deleted, all children must be soft-deleted, too.',
                'This check is for inline children defined *without* foreign_table_field in TCA.',
                'This check finds not soft-deleted children and sets soft-deleted for for live records,',
                'or removes them when dealing with workspace records.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findingsByTable = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            if (!$this->tcaHelper->getDeletedField($childTableName)) {
                // Skip child table if it is not soft-delete aware
                continue;
            }
            $parentTableName = $inlineChild['parentTableName'];
            $parentTableDeleteField = $this->tcaHelper->getDeletedField($parentTableName);
            if (!$parentTableDeleteField) {
                // Skip if parent table is not soft-delete aware
                continue;
            }
            if (!$this->tableHelper->tableExistsInDatabase($parentTableName)) {
                continue;
            }
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($childTableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];

            $selectFields = [
                'uid',
                'pid',
                $fieldNameOfParentTableUid,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Do not consider deleted records: We want to find children deleted=0 with parents deleted=1.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select(...$selectFields)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->gt($fieldNameOfParentTableUid, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid');
            foreach ($inlineChild['matchFields'] as $matchFieldName => $matchFieldValue) {
                // Child table may have multiple parent tables, consider only rows of this parent.
                $parameter = $this->tableHelper->fieldIsInteger($childTableName, $matchFieldName)
                    ? $queryBuilder->createNamedParameter((int)$matchFieldValue, Connection::PARAM_INT)
                    : $queryBuilder->createNamedParameter((string)$matchFieldValue);
                $queryBuilder->andWhere($queryBuilder->expr()->eq($matchFieldName, $parameter));
            }
            $result = $queryBuilder->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                try {
                    $parentRow = $this->recordsHelper->getRecord($run->statements, $parentTableName, ['uid', $parentTableDeleteField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    if ((bool)$parentRow[$parentTableDeleteField]) {
                        $parentUid = (int)$inlineChildRow[$fieldNameOfParentTableUid];
                        // Keyed by uid: Two parent fields of the same parent table may select the same row.
                        $findingsByTable[$childTableName][(int)$inlineChildRow['uid']] = new Finding(
                            new RecordKey($childTableName, (int)$inlineChildRow['uid']),
                            (int)$inlineChildRow['pid'],
                            [$this->softOrHardDeleteChange($childTableName, $inlineChildRow)],
                            new Reason(
                                'parent-deleted',
                                'Deleted parent "' . $parentTableName . ':' . $parentUid . '"',
                                ['parentTable' => $parentTableName, 'parentUid' => $parentUid]
                            ),
                        );
                    }
                } catch (NoSuchRecordException $e) {
                    // Record existence has been checked by InlineForeignFieldChildrenParentMissing already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
            }
        }
        $findings = [];
        foreach ($findingsByTable as $tableFindings) {
            ksort($tableFindings);
            array_push($findings, ...array_values($tableFindings));
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        $fields = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent() as $inlineChild) {
            if ($inlineChild['tableName'] === $tableName
                && !in_array($inlineChild['fieldNameOfParentTableUid'], $fields, true)
            ) {
                $fields[] = $inlineChild['fieldNameOfParentTableUid'];
            }
        }
        return $fields;
    }
}
