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
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Helper\PagesTreeHelper;

/**
 * An important early check: Find pages that have no proper connection to the tree root.
 */
final readonly class PagesBrokenTree extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private PagesTreeHelper $pagesTreeHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check page tree integrity',
            [ActionTag::Remove],
            [
                'This health check finds "pages" records with their "pid" set to pages that do',
                'not exist in the database. Pages without proper connection to the tree root are never',
                'shown in the backend. They are removed.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        // All pages rows count as connected, including deleted and workspace rows.
        $uidToPid = $this->pagesTreeHelper->getAllPageUidToPid();
        $connected = $this->pagesTreeHelper->resolveClosestSeed($uidToPid, [0 => true]);
        $findings = [];
        foreach ($uidToPid as $uid => $pid) {
            if ($connected[$uid] === null) {
                $findings[$uid] = new Finding(
                    new RecordKey('pages', $uid),
                    $pid,
                    [new RecordChange(RecordChangeType::Delete, 'pages', $uid)],
                );
            }
        }
        ksort($findings);
        return array_values($findings);
    }
}
