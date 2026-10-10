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
 * An important early check: Find pages that have no proper connection to the tree root.
 */
final class PagesBrokenTree extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly PagesTreeHelper $pagesTreeHelper,
    ) {}

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

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        // All pages rows count as connected, including deleted and workspace rows.
        $uidToPid = $this->pagesTreeHelper->getAllPageUidToPid();
        $connected = $this->pagesTreeHelper->resolveClosestSeed($uidToPid, [0 => true]);
        $danglingPages = [];
        foreach ($uidToPid as $uid => $pid) {
            if ($connected[$uid] === null) {
                $danglingPages['pages'][$uid] = ['uid' => $uid, 'pid' => $pid];
            }
        }
        if ($danglingPages === []) {
            return [];
        }
        ksort($danglingPages['pages']);
        return ['pages' => array_values($danglingPages['pages'])];
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($run, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }
}
