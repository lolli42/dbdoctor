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
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deleted localized tt_content records must point to a sys_language_uid=0 parent that exists.
 * This is a "safe" variant since it handles deleted=1 records only.
 */
final class TtContentDeletedLocalizedParentExists extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for deleted localized tt_content records without parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'Soft deleted localized records in "tt_content" (sys_language_uid > 0) having',
            'l18n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.',
            'Records violating this are removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent')->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', 1),
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $recordsHelper->getRecord('tt_content', ['uid'], (int)$row['l18n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                // Match if parent does not exist at all
                $tableRows['tt_content'][] = $row;
            }
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($io, $simulate, 'tt_content', $affectedRecords['tt_content'] ?? []);
    }
}
