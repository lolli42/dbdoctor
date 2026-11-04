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
use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field children that are not deleted but parent is deleted.
 */
final readonly class InlineForeignFieldChildrenParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline foreign field records with deleted=1 parent',
            [ActionTag::SoftDelete, ActionTag::WorkspaceRemove],
            [
                'TCA inline foreign field records point to a parent record. When this parent is',
                'soft-deleted, all children must be soft-deleted, too.',
                'This check finds not soft-deleted children and sets soft-deleted for for live records,',
                'or removes them when dealing with workspace records.',
            ],
        );
    }

    public function detect(PreparedStatements $statements): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild->tableName;
            if (!$this->tcaHelper->getDeletedField($childTableName)) {
                // Skip child table if it is not soft-delete aware
                continue;
            }
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($childTableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $fieldNameOfParentTableName = $inlineChild->fieldNameOfParentTableName;
            $fieldNameOfParentTableUid = $inlineChild->fieldNameOfParentTableUid;

            $selectFields = [
                'uid',
                'pid',
                $fieldNameOfParentTableName,
                $fieldNameOfParentTableUid,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Do not consider deleted records: We want to find children deleted=0 with parents deleted=1.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->gt($fieldNameOfParentTableUid, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq($fieldNameOfParentTableName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull($fieldNameOfParentTableName)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                $parentTableName = (string)$inlineChildRow[$fieldNameOfParentTableName];
                $parentTableDeleteField = $this->tcaHelper->getDeletedField($parentTableName);
                if (!$parentTableDeleteField) {
                    // Skip if parent table is not soft-delete aware. A parent table without TCA
                    // is handled by the earlier InlineForeignFieldChildrenParentMissing.
                    continue;
                }
                try {
                    $parentRow = $this->recordsHelper->getRecord($statements, $parentTableName, [$parentTableDeleteField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    if ((bool)$parentRow[$parentTableDeleteField]) {
                        $parentUid = (int)$inlineChildRow[$fieldNameOfParentTableUid];
                        $findings[] = new Finding(
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
                    // Missing parents are handled by the earlier InlineForeignFieldChildrenParentMissing.
                    // They are still there in check mode, where earlier checks change nothing.
                    continue;
                }
            }
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            if ($inlineChild->tableName === $tableName) {
                return [
                    $inlineChild->fieldNameOfParentTableName,
                    $inlineChild->fieldNameOfParentTableUid,
                ];
            }
        }
        return [];
    }
}
