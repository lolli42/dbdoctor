<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Renderer;

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

use Lolli\Dbdoctor\Helper\PagesRootlineHelper;

final class AffectedPagesRenderer
{
    private PagesRootlineHelper $pagesRootlineHelper;

    public function __construct(
        PagesRootlineHelper $pagesRootlineHelper
    ) {
        $this->pagesRootlineHelper = $pagesRootlineHelper;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $tableRecordRows
     * @return string[]
     */
    public function getHeader(array $tableRecordRows): array
    {
        $affectedPids = $this->getAffectedPids($tableRecordRows);
        $maxRootlineCount = 0;
        foreach ($affectedPids as $pid => $count) {
            $thisRootline = $this->pagesRootlineHelper->getRootline($pid);
            $rootlineCount = count($thisRootline);
            if ($rootlineCount > $maxRootlineCount) {
                $maxRootlineCount = $rootlineCount;
            }
        }
        $header = ['records'];
        for ($i = 1; $i <= $maxRootlineCount; $i++) {
            $header[] = 'segment ' . $i;
        }
        return $header;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $tableRecordRows
     * @return array<int, array<int, int<1, max>|string>>
     */
    public function getRows(array $tableRecordRows): array
    {
        $affectedPids = $this->getAffectedPids($tableRecordRows);
        $rows = [];
        foreach ($affectedPids as $pid => $count) {
            $thisRootline = $this->pagesRootlineHelper->getRootline($pid);
            $row = [$count];
            foreach ($thisRootline as $rootlineItem) {
                $rowParams = [$rootlineItem['uid']];
                $isMissing = $rootlineItem['_isMissing'];
                if ($isMissing) {
                    $rowParams[] = '<comment>missing</comment>';
                }
                if (!$isMissing && $rootlineItem['deleted']) {
                    $rowParams[] = '<info>deleted</info>';
                }
                if (!$isMissing && $rootlineItem['t3ver_wsid'] > 0) {
                    $rowParams[] = '<info>ws-' . $rootlineItem['t3ver_wsid'] . '</info>';
                }
                $rowItem = '[' . implode('|', $rowParams) . ']';
                if (!$isMissing) {
                    $rowItem .= $rootlineItem['title'];
                }
                $row[] = $rowItem;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $tableRecordRows
     * @return array<int, int<1, max>>
     */
    private function getAffectedPids(array $tableRecordRows): array
    {
        $affectedPids = [];
        foreach ($tableRecordRows as $tableName => $rows) {
            $uidField = 'pid';
            if ($tableName === 'pages') {
                $uidField = 'uid';
            }
            foreach ($rows as $row) {
                $value = (int)$row[$uidField];
                if (!isset($affectedPids[$value])) {
                    $affectedPids[$value] = 1;
                } else {
                    $affectedPids[$value] ++;
                }
            }
        }
        return $affectedPids;
    }
}
