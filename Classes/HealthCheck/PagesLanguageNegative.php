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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Find not-deleted pages with sys_language_uid < 0.
 */
final class PagesLanguageNegative extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check pages with negative language');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'This health check finds not deleted "pages" records with sys_language_uid < 0. The',
            'backend does not allow language "-1" (all languages) for pages. Such pages are not shown',
            'in the page tree, menus and routing, but their sub pages and records may still be in use.',
            'They are soft-deleted in live and removed if they are workspace records. Later checks',
            'handle sub pages, translations and records of these pages.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Do not consider page records that have been set to deleted already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 't3ver_wsid')
            ->from('pages')
            ->where($queryBuilder->expr()->lt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $affectedRecords['pages'][] = $row;
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecordsOfTable($io, $simulate, 'pages', $affectedRecords['pages'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['languageField']);
    }
}
