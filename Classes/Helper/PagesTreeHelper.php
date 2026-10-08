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
use Doctrine\DBAL\Result;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bulk operations on the page tree, based on a single uid / pid query.
 *
 * This is deliberately not based on core RootlineUtility, which is unsuitable for
 * bulk lookups: It fetches full page rows including relation fields per page, keeps
 * them in runtime cache and writes persistent rootline cache entries as side effect.
 *
 * Not to be confused with PagesRootlineHelper, which renders single rootlines for output.
 */
final readonly class PagesTreeHelper
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * uid => pid of all pages rows, including deleted and workspace rows.
     *
     * @return array<int, int>
     */
    public function getAllPageUidToPid(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid')->from('pages');
        return $this->fetchUidToPid($queryBuilder->executeQuery());
    }

    /**
     * uid => pid of not deleted live pages rows.
     *
     * @return array<int, int>
     */
    public function getLivePageUidToPid(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder->select('uid', 'pid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        return $this->fetchUidToPid($queryBuilder->executeQuery());
    }

    /**
     * For each page in $uidToPid, find the closest page up the tree - the page itself
     * first - that is a key in $seeds, and return its value. Seed 0 is the tree root.
     * Pages that do not reach a seed get null: Their pid chain ends at a page not in
     * $uidToPid, or loops.
     *
     * Each page is walked only once, independent of uid order and tree depth.
     *
     * @template T
     * @param array<int, int> $uidToPid
     * @param array<int, T> $seeds
     * @return array<int, T|null>
     */
    public function resolveClosestSeed(array $uidToPid, array $seeds): array
    {
        $resolved = [];
        foreach ($uidToPid as $uid => $pid) {
            if (array_key_exists($uid, $resolved)) {
                continue;
            }
            $walkedUids = [];
            $currentUid = $uid;
            $value = null;
            while (true) {
                if (array_key_exists($currentUid, $resolved)) {
                    $value = $resolved[$currentUid];
                    break;
                }
                if (array_key_exists($currentUid, $seeds)) {
                    $value = $seeds[$currentUid];
                    break;
                }
                if (!isset($uidToPid[$currentUid]) || isset($walkedUids[$currentUid])) {
                    // Page does not exist, or pid loop.
                    break;
                }
                $walkedUids[$currentUid] = true;
                $currentUid = $uidToPid[$currentUid];
            }
            foreach ($walkedUids as $walkedUid => $_) {
                $resolved[$walkedUid] = $value;
            }
            $resolved[$uid] = $value;
        }
        return $resolved;
    }

    /**
     * @return array<int, int>
     */
    private function fetchUidToPid(Result $result): array
    {
        $uidToPid = [];
        while ($row = $result->fetchAssociative()) {
            $uidToPid[(int)$row['uid']] = (int)$row['pid'];
        }
        return $uidToPid;
    }
}
