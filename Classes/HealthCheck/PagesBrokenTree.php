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

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An important early check: Find pages that have no proper connection to the tree root.
 */
final class PagesBrokenTree extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check page tree integrity');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'This health check finds "pages" records with their "pid" set to pages that do',
            'not exist in the database. Pages without proper connection to the tree root are never',
            'shown in the backend. They are removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid')->from('pages')->orderBy('uid')->executeQuery();
        $connectedPageUids = [];
        // uid 0 is "valid"
        $connectedPageUids[0] = true;
        $unknownPageUidPidPairs = [];
        while ($pageRow = $result->fetchAssociative()) {
            if ((int)$pageRow['pid'] === 0) {
                // Pages with pid 0 are good and sorted out already.
                $connectedPageUids[(int)$pageRow['uid']] = true;
            } else {
                $unknownPageUidPidPairs[(int)$pageRow['uid']] = (int)$pageRow['pid'];
            }
        }
        $unknownUidPidPairsCount = count($unknownPageUidPidPairs);
        if ($unknownUidPidPairsCount > 0) {
            // If there are currently "unknown status" rows, have a loop that reduces the
            // "unknown" list until it does not change anymore or is empty: Each run looks
            // if "pid" is in "connected" list and adds itself as valid "uid" if so.
            while (true) {
                foreach ($unknownPageUidPidPairs as $uid => $pid) {
                    if (array_key_exists($pid, $connectedPageUids)) {
                        $connectedPageUids[$uid] = true;
                        unset($unknownPageUidPidPairs[$uid]);
                    }
                }
                $unknownUidPidPairsCountAfter = count($unknownPageUidPidPairs);
                if ($unknownUidPidPairsCountAfter === 0 || $unknownUidPidPairsCountAfter === $unknownUidPidPairsCount) {
                    break;
                }
                $unknownUidPidPairsCount = $unknownUidPidPairsCountAfter;
            }
        }
        $danglingPages = [];
        foreach ($unknownPageUidPidPairs as $uid => $pid) {
            // Everything left in $unknownPageUidPidPairs is not ok.
            $danglingPages['pages'][] = ['uid' => $uid, 'pid' => $pid];
        }
        return $danglingPages;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($io, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }
}
