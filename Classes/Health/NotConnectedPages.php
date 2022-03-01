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
use Lolli\Dbhealth\Helper\PagesRootlineHelper;
use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Renderer\AffectedPagesRenderer;
use Lolli\Dbhealth\Renderer\RecordsRenderer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * An important early check: Find pages that have no proper connection
 * to the tree root.
 */
class NotConnectedPages implements HealthInterface
{
    private ContainerInterface $container;
    private ConnectionPool $connectionPool;

    public function __construct(
        ContainerInterface $container,
        ConnectionPool $connectionPool
    ) {
        $this->container = $container;
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for pages tree integrity');
        $io->text([
            'This health check finds pages with their "pid" set to pages that do not exist',
            'in the database. Pages without proper connection to the tree root are never shown',
            'in the backend. They should be deleted.',
        ]);
    }

    public function process(SymfonyStyle $io, HealthCommand $command): int
    {
        $danglingPages = $this->getDanglingPages();
        $this->outputMainSummary($io, $danglingPages);
        if (empty($danglingPages['pages'])) {
            return self::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Remove records [y,a,r,p,d,?]?</info> ', '?')) {
                case 'y':
                    $this->deleteRecords($io, $danglingPages);
                    $danglingPages = $this->getDanglingPages();
                    $this->outputMainSummary($io, $danglingPages);
                    if (empty($danglingPages['pages'])) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'a':
                    return self::RESULT_ABORT;
                case 'r':
                    $danglingPages = $this->getDanglingPages();
                    $this->outputMainSummary($io, $danglingPages);
                    if (empty($danglingPages['pages'])) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputAffectedPages($io, $danglingPages);
                    break;
                case 'd':
                    $this->outputRecordDetails($io, $danglingPages);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - remove (DELETE, no soft-delete!) records',
                        '    a - abort now',
                        '    r - reload possibly changed data',
                        '    p - show record per page',
                        '    d - show record details',
                        '    ? - print help',
                    ]);
                    break;
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function getDanglingPages(): array
    {
        /** @var PagesRootlineHelper $pagesRootlineHelper */
        $pagesRootlineHelper = $this->container->get(PagesRootlineHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid')->from('pages')->orderBy('uid')->executeQuery();
        $danglingPages = ['pages' => []];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $isInRootline = $pagesRootlineHelper->isInRootline((int)$row['uid']);
            if (!$isInRootline) {
                $danglingPages['pages'][] = $row;
            }
        }
        return $danglingPages;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingPages
     */
    private function deleteRecords(SymfonyStyle $io, array $danglingPages): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($danglingPages as $tableName => $rows) {
            $io->note('Deleting records on table: ' . $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $sql = $recordsHelper->deleteTcaRecord($tableName, (int)$row['uid']);
                $io->text($sql);
                $count ++;
            }
            $io->warning('Deleted "' . $count . '" records from "' . $tableName . '" table');
        }
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingPages
     */
    private function outputMainSummary(SymfonyStyle $io, array $danglingPages): void
    {
        if (!count($danglingPages['pages'])) {
            $io->success('All pages are properly connected in the page tree');
        } else {
            $ioText = [
                'Found "' . count($danglingPages['pages']) . '" record in table "pages" without connection to the tree root',
            ];
            $io->warning($ioText);
        }
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingPages
     */
    private function outputAffectedPages(SymfonyStyle $io, array $danglingPages): void
    {
        /** @var AffectedPagesRenderer $affectedPagesRenderer */
        $affectedPagesRenderer = $this->container->get(AffectedPagesRenderer::class);
        $io->note('Found records per page:');
        $io->table(
            $affectedPagesRenderer->getHeader($danglingPages),
            $affectedPagesRenderer->getRows($danglingPages)
        );
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingPages
     */
    private function outputRecordDetails(SymfonyStyle $io, array $danglingPages): void
    {
        /** @var RecordsRenderer $recordsRenderer */
        $recordsRenderer = $this->container->get(RecordsRenderer::class);
        foreach ($danglingPages as $tableName => $rows) {
            $uidArray = array_column($rows, 'uid');
            $io->note('Table "' . $tableName . '":');
            $io->table(
                $recordsRenderer->getHeader($tableName),
                $recordsRenderer->getRows($tableName, $uidArray)
            );
        }
    }
}
