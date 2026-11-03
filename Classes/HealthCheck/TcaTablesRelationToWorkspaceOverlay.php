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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Helper\ListRelationHelper;
use Lolli\Dbdoctor\Helper\MmRelationHelper;
use Lolli\Dbdoctor\Helper\RelationProblem;
use Lolli\Dbdoctor\Helper\RelationTargetHelper;
use Lolli\Dbdoctor\Tca\ForeignTableListField;
use Lolli\Dbdoctor\Tca\GroupField;
use Lolli\Dbdoctor\Tca\MmField;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Relations of live records must point to live records, never to a workspace overlay (t3ver_oid > 0):
 * A changed record, a move pointer or a delete placeholder of a workspace. Overlays are found through
 * their live record. The live frontend loads records with t3ver_wsid = 0 only, and the workspace
 * preview loads overlays through their live record, not by their own uid: Such relations point to
 * nothing. When the workspace is published or discarded, the overlay record is removed, the relation
 * points to a missing record then.
 *
 * Relations of workspace records are not touched: The core writes relations of workspace records
 * pointing to records of their workspace on purpose, for instance file references of a changed page,
 * and the reference index has them, too. Core command cleanup:missingrelations does not tell them
 * apart: It reports all sys_refindex rows pointing to t3ver_oid > 0, also those of workspace records
 * (core 13.4 and 14.3).
 *
 * Relations to overlays are removed, as cleanup:missingrelations does: This does not change what the
 * frontend shows, and does what the existing relation checks do as soon as the overlay is published
 * or discarded. Replacing them by the live record (t3ver_oid) would show records nobody selected.
 * - Fields storing relations as list: The item is removed from the list, like GroupFieldRelationMissing.
 * - Fields with MM table: The MM rows of the live local record are removed, like GroupFieldMmRelationMissing.
 *   Not if the target table edits the relation from the opposite side (MM_opposite_field), like the field
 *   "categories" of pages for sys_category "items": The DataHandler writes MM rows of a workspace overlay of
 *   the opposite side with the live record in uid_local and the overlay in uid_foreign, they belong to the
 *   workspace.
 * - Inline children with foreign_field: A live child whose parent is an overlay is removed, like
 *   InlineForeignFieldChildrenParentMissing does with children of missing parents. Workspace children
 *   point to the live parent, a live child of an overlay parent is never created by the core.
 *
 * Language pointer fields (transOrigPointerField, translationSource) and pid are no relation fields,
 * they are not handled here.
 */
final readonly class TcaTablesRelationToWorkspaceOverlay extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ListRelationHelper $listRelationHelper,
        private MmRelationHelper $mmRelationHelper,
        private RelationTargetHelper $relationTargetHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for relations of live records to workspace overlays',
            [ActionTag::UpdateFields, ActionTag::Remove],
            [
                'Relations of live records must point to live records. A workspace overlay (t3ver_oid > 0)',
                'is found through its live record, a relation to the overlay itself shows nothing in live and',
                'in the workspace preview, and points to a missing record once the workspace is published.',
                'This check finds such relations in group, select, category and inline fields, as list and',
                'with MM table, and removes them, as core command cleanup:missingrelations does. Live inline',
                'children whose parent is an overlay are removed. Relations of workspace records point to',
                'records of their workspace on purpose and are not touched, as well as MM rows of overlays',
                'editing a relation from the opposite side, like the categories of a changed page.',
            ],
        );
    }

    public function detect(PreparedStatements $statements): array
    {
        if (!$this->hasOverlays()) {
            // Instances without workspace overlays, the common case: Nothing to find, no relation field is read.
            return [];
        }
        return [
            ...$this->listRelationHelper->getFindings($this->getListFields(), RelationProblem::WorkspaceOverlay),
            ...$this->mmRelationHelper->getFindings($statements, $this->getMmFields(), RelationProblem::WorkspaceOverlay),
            ...$this->getInlineChildrenFindings($statements),
        ];
    }

    /**
     * True if any workspace aware table has a workspace overlay (t3ver_oid > 0).
     */
    private function hasOverlays(): bool
    {
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            if (!$this->tableHelper->tableExistsInDatabase($tableName)) {
                continue;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $overlayUid = $queryBuilder->select('uid')
                ->from($tableName)
                ->where($queryBuilder->expr()->gt('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if ($overlayUid !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return iterable<GroupField|ForeignTableListField>
     */
    private function getListFields(): iterable
    {
        yield from $this->tcaHelper->getNextGroupFieldWithoutMm();
        yield from $this->tcaHelper->getNextSelectFieldWithoutMm();
        yield from $this->tcaHelper->getNextInlineFieldWithUidList();
    }

    /**
     * @return iterable<MmField>
     */
    private function getMmFields(): iterable
    {
        yield from $this->tcaHelper->getNextGroupFieldWithMm();
        yield from $this->tcaHelper->getNextSelectFieldWithMm();
        yield from $this->tcaHelper->getNextInlineFieldWithMm();
    }

    /**
     * Live inline children whose parent, by foreign_field and foreign_table_field, or by foreign_field
     * and the table of the parent field told apart by foreign_match_fields, is a workspace overlay.
     *
     * @return list<Finding>
     */
    private function getInlineChildrenFindings(PreparedStatements $statements): array
    {
        // Keyed by table and uid: Two parent fields of the same parent table may select the same row.
        $findingsByTable = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $findingsByTable = $this->addChildrenOfOverlayParentFindings(
                $statements,
                $findingsByTable,
                $inlineChild->tableName,
                $inlineChild->fieldNameOfParentTableUid,
                $inlineChild->fieldNameOfParentTableName,
                null,
                []
            );
        }
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent() as $inlineParent) {
            if ($this->tcaHelper->getWorkspaceIdField($inlineParent->parentTableName) === null) {
                continue;
            }
            $findingsByTable = $this->addChildrenOfOverlayParentFindings(
                $statements,
                $findingsByTable,
                $inlineParent->tableName,
                $inlineParent->fieldNameOfParentTableUid,
                null,
                $inlineParent->parentTableName,
                $inlineParent->matchFields
            );
        }
        $findings = [];
        foreach ($findingsByTable as $tableFindings) {
            ksort($tableFindings);
            $findings = [...$findings, ...array_values($tableFindings)];
        }
        return $findings;
    }

    /**
     * Either $parentTableField is set, the field of the child holding the parent table name, or
     * $parentTableName with the match fields selecting the children of this parent table.
     *
     * @param array<string, array<int, Finding>> $findingsByTable
     * @param array<string, int|string> $matchFields
     * @return array<string, array<int, Finding>>
     */
    private function addChildrenOfOverlayParentFindings(
        PreparedStatements $statements,
        array $findingsByTable,
        string $childTableName,
        string $parentUidField,
        ?string $parentTableField,
        ?string $parentTableName,
        array $matchFields,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
        // Soft-deleted children are handled as well.
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid', $parentUidField)->from($childTableName);
        if ($this->tableHelper->fieldIsInteger($childTableName, $parentUidField)) {
            $queryBuilder->where($queryBuilder->expr()->gt($parentUidField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->neq($parentUidField, $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->isNotNull($parentUidField)
            );
        }
        if ($parentTableField !== null) {
            $queryBuilder->addSelect($parentTableField);
        }
        foreach ($matchFields as $matchFieldName => $matchFieldValue) {
            // Child table may have multiple parent tables, consider only rows of this parent.
            $parameter = $this->tableHelper->fieldIsInteger($childTableName, $matchFieldName)
                ? $queryBuilder->createNamedParameter((int)$matchFieldValue, Connection::PARAM_INT)
                : $queryBuilder->createNamedParameter((string)$matchFieldValue);
            $queryBuilder->andWhere($queryBuilder->expr()->eq($matchFieldName, $parameter));
        }
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($childTableName);
        if ($workspaceIdField !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        $result = $queryBuilder->orderBy('uid')->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string|null> $row */
            $rowParentTableName = $parentTableName ?? (string)$row[(string)$parentTableField];
            $parentUid = (int)$row[$parentUidField];
            if ($parentUid <= 0
                || !is_array($GLOBALS['TCA'][$rowParentTableName] ?? false)
                || !$this->relationTargetHelper->hasProblem($statements, RelationProblem::WorkspaceOverlay, $rowParentTableName, $parentUid)
            ) {
                continue;
            }
            $findingsByTable[$childTableName][(int)$row['uid']] = new Finding(
                new RecordKey($childTableName, (int)$row['uid']),
                (int)$row['pid'],
                [new RecordChange(RecordChangeType::Delete, $childTableName, (int)$row['uid'])],
                new Reason(
                    'parent-overlay',
                    'Parent "' . $rowParentTableName . ':' . $parentUid . '" is a workspace overlay',
                    ['parentTable' => $rowParentTableName, 'parentUid' => $parentUid]
                ),
            );
        }
        return $findingsByTable;
    }
}
