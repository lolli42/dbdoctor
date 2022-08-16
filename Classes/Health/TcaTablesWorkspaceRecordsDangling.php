<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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

use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * There is a hook in ext:workspaces that discards (= remove from DB) all existing workspace
 * records of a workspace, when the workspace record itself (table sys_workspace) is removed
 * or set to deleted=1.
 *
 * This class looks for workspace records in all tables which may have been missed.
 */
class TcaTablesWorkspaceRecordsDangling extends AbstractHealth implements HealthInterface, HealthDeleteInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for workspace records of deleted sys_workspace\'s');
        $io->text([
            '[DELETE] When a workspace (table "sys_workspace") is deleted, all existing workspace',
            '         overlays in all tables of this workspace are discarded (= removed from DB). When this',
            '         goes wrong, or if the workspace extension is removed, the system ends up with "dangling"',
            '         workspace records in tables. This health check finds those records and allows removal.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $allowedWorkspacesUids = $this->getAllowedWorkspaces();
        $affectedRows = [];
        $tcaHelper = $this->container->get(TcaHelper::class);
        foreach ($tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Since workspace records have no deleted=1, we remove all restrictions here: If a sys_workspace
            // has been removed at some point, there shouldn't be *any* records assigned to this workspace.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->notIn('t3ver_wsid', $queryBuilder->quoteArrayBasedValueListToIntegerList($allowedWorkspacesUids))
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRows[$tableName][(int)$row['uid']] = $row;
            }
        }
        return $affectedRows;
    }

    /**
     * @return array<int, int|string>
     */
    private function getAllowedWorkspaces(): array
    {
        $allowedWorkspaces = [];
        $tableHelper = $this->container->get(TableHelper::class);
        if ($tableHelper->tableExistsInDatabase('sys_workspace')) {
            // List of active workspaces.
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $deletedRestriction = GeneralUtility::makeInstance(DeletedRestriction::class);
            $queryBuilder->getRestrictions()->removeAll()->add($deletedRestriction);
            // Note we're NOT allowing deleted=1 records here: A sys_workspace being deleted gets all it's elements
            // discarded (= removed) by the default core implementation, so we treat those as 'dangling'.
            $result = $queryBuilder->select('uid', 'title')->from('sys_workspace')->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $allowedWorkspaces[$row['uid']] = $row;
            }
        }
        // t3ver_wsid=0 are *always* allowed, of course.
        return array_merge([0], array_keys($allowedWorkspaces));
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteRecords($io, $simulate, $affectedRecords);
    }
}
