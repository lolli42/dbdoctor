<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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
 * Find translated pages that have no sys_language_uid=0 parent.
 */
class PagesTranslatedLanguageParentMissing extends AbstractHealth implements HealthInterface, HealthDeleteInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check pages with missing language parent');
        $io->text([
            '[DELETE] This health check finds translated "pages" records (sys_language_uid > 0) with',
            '         their default language record (l10n_parent field) not existing in the database.',
            '         Those translated pages are never shown in backend and frontend and should be deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Deleted pages without valid parent page are translated, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'l10n_parent')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $recordsHelper->getRecord('pages', ['uid'], (int)$row['l10n_parent']);
            } catch (NoSuchRecordException $e) {
                $affectedRecords['pages'][] = $row;
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
