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

use Lolli\Dbdoctor\Helper\PagesRootlineHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An important early check: Find pages that have no proper connection to the tree root.
 */
final class PagesBrokenTree extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check page tree integrity');
        $io->text([
            '[DELETE] This health check finds "pages" records with their "pid" set to pages that do',
            '         not exist in the database. Pages without proper connection to the tree root are never',
            '         shown in the backend. They should be deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $pagesRootlineHelper = $this->container->get(PagesRootlineHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid')->from('pages')->orderBy('uid')->executeQuery();
        $danglingPages = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $isInRootline = $pagesRootlineHelper->isInRootline((int)$row['uid']);
            if (!$isInRootline) {
                $danglingPages['pages'][] = $row;
            }
        }
        return $danglingPages;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($io, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }
}
