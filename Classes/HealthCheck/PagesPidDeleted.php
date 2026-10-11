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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Helper\PagesTreeHelper;

/**
 * Find not-deleted pages that are located within a deleted page, directly or further down the tree.
 */
final readonly class PagesPidDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private PagesTreeHelper $pagesTreeHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check pages within deleted pages',
            [self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE],
            [
                'This health check finds not deleted "pages" records with their "pid" set to a soft-deleted',
                'page, including whole sub trees below a deleted page. The core deletes sub pages when a',
                'page is deleted, those pages are not reachable in backend and frontend anymore. They are',
                'soft-deleted in live and removed if they are workspace records.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
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
        $findings = [];
        foreach ($notDeletedPageWorkspaceIds as $uid => $workspaceId) {
            if ($withinDeletedPage[$uid] === true) {
                $findings[] = new Finding(
                    new RecordKey('pages', $uid),
                    $uidToPid[$uid],
                    [$this->softOrHardDeleteChange('pages', ['uid' => $uid, 't3ver_wsid' => $workspaceId])],
                );
            }
        }
        return $findings;
    }
}
