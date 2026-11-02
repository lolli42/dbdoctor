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
use Lolli\Dbdoctor\Finding\Reason;

/**
 * Stateless service telling if the target record of a relation has a RelationProblem,
 * shared by the relation helpers.
 */
final readonly class RelationTargetHelper
{
    public function __construct(
        private TcaHelper $tcaHelper,
        private RecordsHelper $recordsHelper,
        private TableHelper $tableHelper,
    ) {}

    /**
     * Missing: The record does not exist. WorkspaceOverlay: The record exists and is a workspace
     * overlay (t3ver_oid > 0): Changed records, move pointers and delete placeholders. New records
     * of a workspace have t3ver_oid 0, they become live records with the same uid when published.
     * Tables that do not exist in the database are no problem, the relation can not be checked.
     */
    public function hasProblem(PreparedStatements $statements, RelationProblem $problem, string $tableName, int $uid): bool
    {
        if (!$this->tableHelper->tableExistsInDatabase($tableName)) {
            return false;
        }
        if ($problem === RelationProblem::WorkspaceOverlay) {
            if ($this->tcaHelper->getWorkspaceIdField($tableName) === null) {
                return false;
            }
            try {
                $record = $this->recordsHelper->getRecord($statements, $tableName, ['uid', 't3ver_oid'], $uid);
            } catch (NoSuchRecordException $e) {
                // Missing records are handled by other checks.
                return false;
            }
            return (int)$record['t3ver_oid'] > 0;
        }
        try {
            $this->recordsHelper->getRecord($statements, $tableName, ['uid'], $uid);
        } catch (NoSuchRecordException $e) {
            return true;
        }
        return false;
    }

    /**
     * Reason of a finding of a relation field with relations having the problem.
     *
     * @param list<string> $relations "tableName:uid" of the target records
     */
    public function getReason(RelationProblem $problem, string $fieldName, array $relations): Reason
    {
        if ($problem === RelationProblem::WorkspaceOverlay) {
            return new Reason(
                'relation-to-overlay',
                'Field "' . $fieldName . '": Workspace overlay ' . implode(', ', $relations),
                ['overlays' => $relations]
            );
        }
        return new Reason(
            'relation-missing',
            'Field "' . $fieldName . '": Missing ' . implode(', ', $relations),
            ['missing' => $relations]
        );
    }
}
