<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Helper;

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

use Doctrine\DBAL\Statement;
use TYPO3\CMS\Core\Database\ConnectionPool;

class PagesHelper
{
    private array $rootlineCache = [];
    private ?Statement $preparedPagesUidStatement = null;
    private ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getPagesDetails(array $tableRecordRows): array
    {
        // Restructure incoming ['table'][]['pid'] array to ['pid'] = count.
        $affectedPids = [];
        foreach ($tableRecordRows as $tableName => $rows) {
            $uidField = 'pid';
            if ($tableName === 'pages') {
                $uidField = 'uid';
            }
            foreach ($rows as $row) {
                if (!isset($affectedPids[$row[$uidField]])) {
                    $affectedPids[$row[$uidField]] = 1;
                } else {
                    $affectedPids[$row[$uidField]] ++;
                }
            }
        }

        $formattedRows = [];
        foreach ($affectedPids as $pid => $count) {
            $rootline = $this->getRootline($pid);
            $formattedRootlineArray = [];
            foreach ($rootline as $rootlineItem) {
                $formattedRootlineArray[] = '[' . $rootlineItem['uid'] . ']' . $rootlineItem['title'];
            }
            $formattedRows[] = [
                'uid' => $pid,
                'records' => $count,
                'path' => implode(' -> ', $formattedRootlineArray),
            ];
        }
        // Free up memory
        $this->rootlineCache = [];
        $this->preparedPagesUidStatement = null;
        return $formattedRows;
    }

    private function getRootline(int $uid, array $rootline = []): array
    {
        if ($uid === 0) {
            array_unshift($rootline, [
                'uid' => 0,
                'pid' => 0,
                'title' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'Root',
            ]);
            return $rootline;
        } else {
            $currentPage = $this->getPage($uid);
            array_unshift($rootline, $currentPage);
            return $this->getRootline((int)$currentPage['pid'], $rootline);
        }
    }

    private function getPage(int $uid): array
    {
        if (isset($this->rootlineCache[$uid])) {
            return $this->rootlineCache[$uid];
        }
        $statement = $this->getPreparedStatementForPagesUid();
        $statement->bindParam(1, $uid);
        $result = $statement->executeQuery();
        $currentPage = $result->fetchAllAssociative();
        $result->free();
        $currentPage = array_pop($currentPage);
        $this->rootlineCache[$currentPage['uid']] = $currentPage;
        return $currentPage;
    }

    private function getPreparedStatementForPagesUid(): Statement
    {
        if ($this->preparedPagesUidStatement) {
            return $this->preparedPagesUidStatement;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid', 'pid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, \PDO::PARAM_INT))
            );
        $this->preparedPagesUidStatement = $queryBuilder->prepare();
        return $this->preparedPagesUidStatement;
    }
}
