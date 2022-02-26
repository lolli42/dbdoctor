<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Health;

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

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use Lolli\Dbhealth\Helper\TableHelper;
use Lolli\Dbhealth\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * There is a hook in ext:workspaces that discards (= remove from DB) all existing workspace
 * records of a workspace, when the workspace record itself (table sys_workspace) is removed
 * or set to deleted=1.
 *
 * This class looks for workspace records in all tables which may have been missed.
 */
class DanglingWorkspaceRecords implements HealthInterface
{
    protected TableHelper $tableHelper;
    protected TcaHelper $tcaHelper;
    protected ConnectionPool $connectionPool;

    public function __construct(
        TableHelper $tableHelper,
        TcaHelper $tcaHelper,
        ConnectionPool $connectionPool
    ) {
        $this->tableHelper = $tableHelper;
        $this->tcaHelper = $tcaHelper;
        $this->connectionPool = $connectionPool;
    }

    public function process(SymfonyStyle $io): void
    {
        $allowedWorkspaces = [];
        $deletedWorkspaces = [];
        if ($this->tableHelper->tableExistsInDatabase('sys_workspace')) {
            // List of active workspaces.
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $queryBuilder->getRestrictions()->removeAll();
            // Note we're fetching deleted=1 records here, but we sort them out and treat them as fully removed:
            // A sys_workspace being deleted gets all it's elements discarded (= removed) by the default core implementation!
            $result = $queryBuilder->select('uid', 'title', 'deleted')->from('sys_workspace')->executeQuery();
            while ($row = $result->fetchAssociative()) {
                if ($row['deleted']) {
                    $deletedWorkspaces[$row['uid']] = $row;
                } else {
                    $allowedWorkspaces[$row['uid']] = $row;
                }
            }
        }
        // t3ver_wsid=0 are *always* allowed, of course.
        $allowedWorkspacesUids = array_merge([0], array_keys($allowedWorkspaces));

        var_dump($allowedWorkspaces);
        var_dump($allowedWorkspacesUids);
        var_dump($deletedWorkspaces);

        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Since workspace records have no deleted=1, we remove all restrictions here: If a sys_workspace
            // has been removed at some point, there shouldn't be *any* records assigned to this workspace.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->notIn('t3ver_wsid', $queryBuilder->quoteArrayBasedValueListToIntegerList($allowedWorkspacesUids))
                )
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                var_dump($row);
            }
        }
    }
}
