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
use Lolli\Dbdoctor\Page\RootlinePage;

final readonly class PagesRootlineHelper
{
    public function __construct(
        private RecordsHelper $recordsHelper,
    ) {}

    /**
     * Pages from the root with uid 0 down to the given page. A page that does not exist is
     * the topmost entry then. Page loops (page uid 1 having pid 2, uid 2 having 1) are found
     * and fixed by PagesBrokenTree. To not fail here or run into an endless loop, the rootline
     * stops at the loop, without the root.
     *
     * @return list<RootlinePage>
     */
    public function getRootline(PreparedStatements $statements, int $uid): array
    {
        return $this->addParentPages($statements, $uid, []);
    }

    /**
     * @param list<RootlinePage> $rootline
     * @return list<RootlinePage>
     */
    private function addParentPages(PreparedStatements $statements, int $uid, array $rootline): array
    {
        if ($uid === 0) {
            $siteName = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'Root');
            array_unshift($rootline, new RootlinePage(0, 0, false, false, false, 0, 0, $siteName));
            return $rootline;
        }
        try {
            $page = $this->recordsHelper->getRecord($statements, 'pages', ['uid', 'pid', 'deleted', 'hidden', 'doktype', 't3ver_wsid', 'title'], $uid);
        } catch (NoSuchRecordException) {
            array_unshift($rootline, new RootlinePage($uid, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'));
            return $rootline;
        }
        $upperPid = (int)$page['pid'];
        if (in_array($upperPid, array_map(static fn(RootlinePage $rootlinePage): int => $rootlinePage->pid, $rootline), true)) {
            return $rootline;
        }
        array_unshift($rootline, new RootlinePage(
            (int)$page['uid'],
            $upperPid,
            false,
            (bool)$page['deleted'],
            (bool)$page['hidden'],
            (int)$page['doktype'],
            (int)$page['t3ver_wsid'],
            (string)$page['title'],
        ));
        return $this->addParentPages($statements, $upperPid, $rootline);
    }
}
