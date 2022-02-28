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

use Lolli\Dbhealth\Commands\HealthCommand;
use Lolli\Dbhealth\Helper\PagesHelper;
use Lolli\Dbhealth\Helper\RecordHelper;
use Lolli\Dbhealth\Helper\TableHelper;
use Lolli\Dbhealth\Helper\TcaHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
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
    private TableHelper $tableHelper;
    private TcaHelper $tcaHelper;
    private RecordHelper $recordHelper;
    private PagesHelper $pagesHelper;
    private ConnectionPool $connectionPool;

    public function __construct(
        TableHelper $tableHelper,
        TcaHelper $tcaHelper,
        RecordHelper $recordHelper,
        PagesHelper $pagesHelper,
        ConnectionPool $connectionPool
    ) {
        $this->tableHelper = $tableHelper;
        $this->tcaHelper = $tcaHelper;
        $this->recordHelper = $recordHelper;
        $this->pagesHelper = $pagesHelper;
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for workspace records of deleted sys_workspace\'s');
        $io->text([
            'When a workspace (table "sys_workspace") is deleted, all existing workspace overlays',
            'in all tables of this workspace are discarded (= removed from DB). When this goes wrong,',
            'or if the workspace extension is removed, the system ends up with "dangling" workspace',
            'records in tables. This health check finds those records and allows removal.'
        ]);
    }

    public function process(SymfonyStyle $io, HealthCommand $command): int
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

        $danglingRows = [];
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
                $danglingRows[$tableName][$row['uid']] = $row;
            }
        }

        $this->outputMainSummary($io, $danglingRows);

        if (empty($danglingRows)) {
            return self::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Remove records [y,a,p,r,?]?</info> ', '?')) {
                case 'y':
                    break 2;
                case 'a':
                    return self::RESULT_ABORT;
                case 'p':
                    $this->outputAffectedPages($io, $danglingRows);
                    break;
                case 'r':
                    $this->outputRecordDetails($io, $danglingRows);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - remove (DELETE, no soft-delete!) records',
                        '    a - abort now',
                        '    p - show affected pages',
                        '    r - show record details',
                        '    ? - print help'
                    ]);
                    break;
            }
        }


        if (count($danglingRows)) {
            foreach ($danglingRows as $tableName => $places) {
                $io->writeln('Found x in' . count($places));
            }
        }
        /**
        $helper = $command->getHelper('question');
        $question->setErrorMessage('Color %s is invalid.');
        $color = $helper->ask($input, $output, $question);
        $output->writeln('You have just selected: '.$color);
         */
    }

    private function outputMainSummary(SymfonyStyle $io, array $danglingRows): void
    {
        if (!count($danglingRows)) {
            $io->success('No workspace records from deleted workspaces');
        } else {
            $ioText = [
                'Found workspace records from deleted workspaces in ' . count($danglingRows) . ' tables:'
            ];
            $tablesString = '';
            foreach ($danglingRows as $tableName => $rows) {
                if (!empty($tablesString)) {
                    $tablesString .= "\n";
                }
                $tablesString .= '"' . $tableName . '": ' . count($rows) . ' records';
            }
            $ioText[] = $tablesString;
            $io->warning($ioText);
        }
    }

    private function outputAffectedPages(SymfonyStyle $io, array $danglingRows): void
    {
        $fields = [
            'uid',
            'records',
            'path'
        ];
        $pagesDetails = $this->pagesHelper->getPagesDetails($danglingRows);
        $io->table($fields, $pagesDetails);
    }

    private function outputRecordDetails(SymfonyStyle $io, array $danglingRows): void
    {
        foreach ($danglingRows as $tableName => $rows) {
            $io->note('Table "' . $tableName . '":');
            $recordDetails = $this->recordHelper->getRecordDetailsForTable($tableName, $rows);
            //$fields = array_keys(current($recordDetails));
            $fields = ['foo', 'bar'];
            //var_dump($fields); die();
            $io->table($fields, $recordDetails);
        }
    }
}
