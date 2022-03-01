<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Health;

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

use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Renderer\AffectedPagesRenderer;
use Lolli\Dbhealth\Renderer\RecordsRenderer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Methods used by multiple health classes.
 */
class AbstractHealth
{
    protected ContainerInterface $container;

    public function injectContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    protected function outputAffectedPages(SymfonyStyle $io, array $danglingRows): void
    {
        $io->note('Found records per page:');
        /** @var AffectedPagesRenderer $affectedPagesHelper */
        $affectedPagesHelper = $this->container->get(AffectedPagesRenderer::class);
        $io->table($affectedPagesHelper->getHeader($danglingRows), $affectedPagesHelper->getRows($danglingRows));
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    protected function outputRecordDetails(SymfonyStyle $io, array $danglingRows): void
    {
        /** @var RecordsRenderer $recordsRenderer */
        $recordsRenderer = $this->container->get(RecordsRenderer::class);
        foreach ($danglingRows as $tableName => $rows) {
            $uidArray = array_column($rows, 'uid');
            $io->note('Table "' . $tableName . '":');
            $io->table(
                $recordsRenderer->getHeader($tableName),
                $recordsRenderer->getRows($tableName, $uidArray)
            );
        }
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingPages
     */
    protected function deleteRecords(SymfonyStyle $io, array $danglingPages): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($danglingPages as $tableName => $rows) {
            $io->note('Deleting records on table: ' . $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $sql = $recordsHelper->deleteTcaRecord($tableName, (int)$row['uid']);
                $io->text($sql);
                $count ++;
            }
            $io->warning('Deleted "' . $count . '" records from "' . $tableName . '" table');
        }
    }
}
