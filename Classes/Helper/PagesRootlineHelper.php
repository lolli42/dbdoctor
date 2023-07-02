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

use Lolli\Dbdoctor\Exception\NoSuchPageException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;

final class PagesRootlineHelper
{
    /**
     * @var array<int, array<string, int|string|bool>>
     */
    private array $rootlineCache = [];

    private RecordsHelper $recordsHelper;

    public function __construct(
        RecordsHelper $recordsHelper
    ) {
        $this->recordsHelper = $recordsHelper;
    }

    public function isInRootline(int $uid): bool
    {
        if ($uid === 0) {
            return true;
        }
        $rootline = $this->getRootline($uid);
        if (!empty($rootline)
            && is_array($rootline[0])
            && ($rootline[0]['uid'] ?? false) === 0
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int, array<string, int|string|bool>> $rootline
     * @return array<int, array<string, int|string|bool>>
     */
    public function getRootline(int $uid, array $rootline = []): array
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
            $currentPage = $this->getPage($uid);
            array_unshift($rootline, $currentPage);
            return $this->getRootline((int)$currentPage['pid'], $rootline);
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
    private function getPage(int $uid): array
    {
        if (isset($this->rootlineCache[$uid])) {
            return $this->rootlineCache[$uid];
        }
        try {
            $currentPage = $this->recordsHelper->getRecord('pages', ['uid', 'pid', 'deleted', 't3ver_wsid', 'title'], $uid);
        } catch (NoSuchRecordException $e) {
            throw new NoSuchPageException('record with uid "' . $uid . '" in table "pages" not found', 1646121409);
        }
        $currentPage['_isMissing'] = false;
        $this->rootlineCache[(int)$currentPage['uid']] = $currentPage;
        return $currentPage;
    }
}
