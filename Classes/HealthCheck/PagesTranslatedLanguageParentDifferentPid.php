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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Find translated pages that are on a different pid than their no sys_language_uid=0 parent.
 */
final class PagesTranslatedLanguageParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check pages with different pid than their language parent');
        $io->text([
            '[DELETE] This health check finds translated "pages" records (sys_language_uid > 0) with',
            '         their default language record (l10n_parent field) on a different pid.',
            '         Those translated pages are shown in backend at a wrong place. They will be deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Deleted pages are considered as well, we remove all restrictions.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'l10n_parent', 'sys_language_uid')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                /** @var array<string, int|string> $languageParentRow */
                $languageParentRow = $recordsHelper->getRecord('pages', ['uid', 'pid'], (int)$row['l10n_parent']);
                if ((int)$row['pid'] !== (int)$languageParentRow['pid']) {
                    $affectedRecords['pages'][] = $row;
                }
            } catch (NoSuchRecordException $e) {
                // Earlier test should have fixed this.
                throw new \RuntimeException(
                    'Pages record with uid="' . $row['uid'] . '" and sys_language_uid="' . $row['sys_language_uid'] . '"'
                    . ' has l10n_parent="' . $row['l10n_parent'] . '", but that record does not exist. A previous check'
                    . ' should have found and fixed this. Please repeat.',
                    1647793649
                );
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecordsOfTable($io, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
