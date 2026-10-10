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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translated records should reference a sys_language_uid that is configured in the
 * site configuration of the page they are located on. Records with a language not
 * in the site configuration are orphaned translations, typically created by copying
 * pages between sites before DataHandler restricted copied translations to the
 * languages of the target site, or by removing a language from a site configuration.
 *
 * Records in sys folders are not checked: Sys folders are typically used as storage
 * for records rendered by other pages, possibly of other sites with more languages,
 * for instance a news storage shared by multiple sites.
 */
final class TcaTablesTranslatedLanguageNotInSiteConfiguration extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PagesTreeHelper $pagesTreeHelper,
    ) {}

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with language not in site configuration');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE, self::TAG_RISKY);
        $io->text([
            'Translated records reference a sys_language_uid. This language must be configured',
            'in the site configuration of the page they are located on. This check finds records',
            'with a sys_language_uid that does not exist in the site configuration. They are soft',
            'deleted if possible, or removed. Records outside of a site are not checked, records in',
            'sys folders neither: They may be rendered by other sites, for instance as shared storage.',
            'Records on other pages may be rendered by other sites as well, check them carefully.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        // Resolve sites with PagesTreeHelper instead of SiteFinder->getSiteByPageId(). This is deliberate:
        // SiteFinder uses RootlineUtility, which is unsuitable for bulk lookups, see PagesTreeHelper.
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
        // Records on deleted pages or on pages that exist in workspaces only are not resolved
        // to a site, as with the core rootline in live context.
        $pageUidToSiteIdentifier = $this->pagesTreeHelper->resolveClosestSeed(
            $this->pagesTreeHelper->getLivePageUidToPid(),
            $rootPageIdToSiteIdentifier
        );
        $sysFolderUids = $this->getLiveSysFolderUids();

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
                $siteIdentifier = $pageUidToSiteIdentifier[$sitePageId] ?? null;
                if ($siteIdentifier === null) {
                    continue;
                }
                if ($tableName !== 'pages' && isset($sysFolderUids[(int)$row['pid']])) {
                    // Records in sys folders may be rendered by other sites, for instance as shared storage.
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

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($run, $simulate, $affectedRecords);
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        $this->outputRecordDetails($run, $affectedRecords, '_reasonBroken', ['languageField', 'transOrigPointerField']);
    }

    /**
     * @return array<int, true>
     */
    private function getLiveSysFolderUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(PageRepository::DOKTYPE_SYSFOLDER, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery();
        $sysFolderUids = [];
        while ($uid = $result->fetchOne()) {
            $sysFolderUids[(int)$uid] = true;
        }
        return $sysFolderUids;
    }
}
