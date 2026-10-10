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
use Lolli\Dbdoctor\Exception\NoSuchPageException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;

final readonly class PagesRootlineHelper
{
    public function __construct(
        private RecordsHelper $recordsHelper,
    ) {}

    /**
     * @param array<int, array<string, int|string|bool>> $rootline
     * @return array<int, array<string, int|string|bool>>
     */
    public function getRootline(PreparedStatements $statements, int $uid, array $rootline = []): array
    {
        if ($uid === 0) {
            array_unshift($rootline, [
                '_isMissing' => false,
                'uid' => 0,
                'pid' => 0,
                'deleted' => false,
                't3ver_wsid' => 0,
                'title' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'Root',
            ]);
            return $rootline;
        }
        try {
            $currentPage = $this->getPage($statements, $uid);
            $upperPid = (int)$currentPage['pid'];
            if (in_array($upperPid, array_column($rootline, 'pid'))) {
                // Page loops (page uid 1 having pid 2, uid 2 having 1) are found and
                // fixed by PagesBrokenTree. To not fail here or run into endless loop,
                // we break this situation and return pages not connected to 0 as rootline.
                return $rootline;
            }
            array_unshift($rootline, $currentPage);
            return $this->getRootline($statements, $upperPid, $rootline);
        } catch (NoSuchPageException $e) {
            array_unshift(
                $rootline,
                [
                    '_isMissing' => true,
                    'uid' => (int)($rootline[0]['pid'] ?? $uid),
                    'pid' => 0,
                    'deleted' => true,
                    't3ver_wsid' => 0,
                    'title' => 'RECORD DOES NOT EXIST',
                ]
            );
            return $rootline;
        }
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function getPage(PreparedStatements $statements, int $uid): array
    {
        try {
            $currentPage = $this->recordsHelper->getRecord($statements, 'pages', ['uid', 'pid', 'deleted', 't3ver_wsid', 'title'], $uid);
        } catch (NoSuchRecordException) {
            throw new NoSuchPageException('record with uid "' . $uid . '" in table "pages" not found', 1646121409);
        }
        $currentPage['_isMissing'] = false;
        return $currentPage;
    }
}
