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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * All TCA records must point to existing pages
 */
class TcaTablesPidMissing extends AbstractHealthCheck implements HealthCheckInterface, HealthDeleteInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records on not existing pages');
        $io->text([
            '[DELETE] TCA records have a pid field set to a single page. This page must exist.',
            '         Records on pages that do not exist anymore should be deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        foreach ($tcaHelper->getNextTcaTable(['pages', 'sys_workspace']) as $tableName) {
            // Iterate all TCA tables, but ignore pages table
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Consider deleted records: If the pid does not exist, they should be deleted, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid')->from($tableName)
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                if ((int)$row['pid'] === 0) {
                    // Records pointing to pid 0 are ok.
                    continue;
                }
                try {
                    $recordsHelper->getRecord('pages', ['uid'], (int)$row['pid']);
                } catch (NoSuchRecordException $e) {
                    $affectedRows[$tableName][] = $row;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteAllRecords($io, $simulate, $affectedRecords);
    }
}
