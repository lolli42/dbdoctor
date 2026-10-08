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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translated records should reference a sys_language_uid that is configured in the
 * site configuration of the page they are located on. Records with a language not
 * in the site configuration are orphaned translations, typically created by copying
 * pages between sites before DataHandler restricted copied translations to the
 * languages of the target site, or by removing a language from a site configuration.
 */
final class TcaTablesTranslatedLanguageNotInSiteConfiguration extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with language not in site configuration');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Translated records reference a sys_language_uid. This language must be configured',
            'in the site configuration of the page they are located on. This check finds records',
            'with a sys_language_uid that does not exist in the site configuration. They are soft',
            'deleted if possible, or removed. Records outside of a site are not checked.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        // Resolve sites with an own page tree walk instead of SiteFinder->getSiteByPageId(). This is
        // deliberate: SiteFinder uses RootlineUtility, which is unsuitable for bulk lookups. It fetches
        // full page rows including relation fields per page, keeps them in runtime cache for the whole
        // run and writes persistent rootline cache entries as side effect. In instances with many pages
        // this costs lots of queries and memory. A single uid / pid query and a tree walk is enough here.
        $rootPageIdToSiteIdentifier = [];
        /** @var array<string, array<int, true>> $siteLanguageIds */
        $siteLanguageIds = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $rootPageIdToSiteIdentifier[$site->getRootPageId()] = $site->getIdentifier();
            foreach ($site->getAllLanguages() as $siteLanguage) {
                $siteLanguageIds[$site->getIdentifier()][$siteLanguage->getLanguageId()] = true;
            }
        }
        if ($rootPageIdToSiteIdentifier === []) {
            return [];
        }
        $pageUidToPid = $this->getLivePageUidToPid();
        /** @var array<int, string|false> $pageUidToSiteIdentifier */
        $pageUidToSiteIdentifier = [];

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            if (!$tableHelper->tableExistsInDatabase($tableName)) {
                // TCA may define tables not yet present in database schema.
                continue;
            }

            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);

            $selectFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];
            if ($workspaceIdField) {
                // Workspace records are removed, including delete placeholders: They
                // carry the language of their live record, which is deleted as well.
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not consider already deleted records: Those are not visible and will not cause
            // issues. Reducing the number of affected records avoids unnecessary noise.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder
                ->select(...$selectFields)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->orderBy('uid');

            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $langId = (int)$row[$languageField];
                // Page translations are located on the pid of their default language page. Resolve the site
                // by the default language page, otherwise translations of site root pages (pid 0) are missed.
                $sitePageId = $tableName === 'pages' && (int)$row[$translationParentField] > 0
                    ? (int)$row[$translationParentField]
                    : (int)$row['pid'];

                // Records on pages without site config (e.g. pid 0 or pages not below a site root)
                // are skipped: No site means no language configuration to validate against.
                $siteIdentifier = $this->resolveSiteIdentifier($sitePageId, $pageUidToPid, $rootPageIdToSiteIdentifier, $pageUidToSiteIdentifier);
                if ($siteIdentifier === false) {
                    continue;
                }
                if (!isset($siteLanguageIds[$siteIdentifier][$langId])) {
                    $row['_reasonBroken'] = 'Language not in site "' . $siteIdentifier . '"';
                    $affectedRows[$tableName][(int)$row['uid']] = $row;
                }
            }
        }
        return $affectedRows;
    }

    /**
     * Not deleted live pages, uid => pid. Records on deleted pages or on pages that exist in
     * workspaces only are not resolved to a site, as with the core rootline in live context.
     *
     * @return array<int, int>
     */
    private function getLivePageUidToPid(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery();
        $pageUidToPid = [];
        while ($row = $result->fetchAssociative()) {
            $pageUidToPid[(int)$row['uid']] = (int)$row['pid'];
        }
        return $pageUidToPid;
    }

    /**
     * Walk up the page tree until a site root page is found, like SiteFinder->getSiteByPageId().
     * Results are added to $pageUidToSiteIdentifier for all pages on the way, so each page is
     * walked only once.
     *
     * @param array<int, int> $pageUidToPid
     * @param array<int, string> $rootPageIdToSiteIdentifier
     * @param array<int, string|false> $pageUidToSiteIdentifier
     */
    private function resolveSiteIdentifier(int $pageId, array $pageUidToPid, array $rootPageIdToSiteIdentifier, array &$pageUidToSiteIdentifier): string|false
    {
        $walkedPageIds = [];
        $currentPageId = $pageId;
        $siteIdentifier = false;
        while (true) {
            if (array_key_exists($currentPageId, $pageUidToSiteIdentifier)) {
                $siteIdentifier = $pageUidToSiteIdentifier[$currentPageId];
                break;
            }
            if (isset($rootPageIdToSiteIdentifier[$currentPageId])) {
                $siteIdentifier = $rootPageIdToSiteIdentifier[$currentPageId];
                break;
            }
            if (!isset($pageUidToPid[$currentPageId]) || isset($walkedPageIds[$currentPageId])) {
                // Page not in live tree, or pid loop: No site.
                break;
            }
            $walkedPageIds[$currentPageId] = true;
            $currentPageId = $pageUidToPid[$currentPageId];
        }
        foreach ($walkedPageIds as $walkedPageId => $_) {
            $pageUidToSiteIdentifier[$walkedPageId] = $siteIdentifier;
        }
        $pageUidToSiteIdentifier[$pageId] = $siteIdentifier;
        return $siteIdentifier;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '_reasonBroken', ['languageField', 'transOrigPointerField']);
    }
}
