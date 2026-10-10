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
use Lolli\Dbdoctor\Helper\PagesTreeHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Find not-deleted pages that are located within a deleted page, directly or further down the tree.
 */
final readonly class PagesPidDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private PagesTreeHelper $pagesTreeHelper,
    ) {}

    public function header(SymfonyStyle $io): void
    {
        $io->section('Check pages within deleted pages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'This health check finds not deleted "pages" records with their "pid" set to a soft-deleted',
            'page, including whole sub trees below a deleted page. The core deletes sub pages when a',
            'page is deleted, those pages are not reachable in backend and frontend anymore. They are',
            'soft-deleted in live and removed if they are workspace records.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'deleted', 't3ver_wsid')->from('pages')->orderBy('uid')->executeQuery();
        $uidToPid = [];
        $deletedPageUids = [];
        // Only the workspace id of not deleted pages: Full rows of all pages need much more memory
        // on big instances, rows are built for affected pages only.
        $notDeletedPageWorkspaceIds = [];
        while ($pageRow = $result->fetchAssociative()) {
            /** @var array<string, int|string> $pageRow */
            $uidToPid[(int)$pageRow['uid']] = (int)$pageRow['pid'];
            if ((int)$pageRow['deleted'] === 1) {
                $deletedPageUids[(int)$pageRow['uid']] = true;
            } else {
                $notDeletedPageWorkspaceIds[(int)$pageRow['uid']] = (int)$pageRow['t3ver_wsid'];
            }
        }
        if ($deletedPageUids === []) {
            return [];
        }
        // A not deleted page is affected if a deleted page is found up the tree.
        $withinDeletedPage = $this->pagesTreeHelper->resolveClosestSeed($uidToPid, $deletedPageUids);
        $affectedPageRows = [];
        foreach ($notDeletedPageWorkspaceIds as $uid => $workspaceId) {
            if ($withinDeletedPage[$uid] === true) {
                $affectedPageRows[] = [
                    'uid' => $uid,
                    'pid' => $uidToPid[$uid],
                    'deleted' => 0,
                    't3ver_wsid' => $workspaceId,
                ];
            }
        }
        if ($affectedPageRows === []) {
            return [];
        }
        return ['pages' => $affectedPageRows];
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecordsOfTable($run, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }
}
