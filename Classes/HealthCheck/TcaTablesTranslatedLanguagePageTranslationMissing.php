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
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translated records (except pages) need a corresponding page translation to exist.
 * A record translated to a language for which no page translation exists on the same
 * pid is orphaned and should be removed. This check only considers languages that are
 * configured in the site configuration.
 */
final class TcaTablesTranslatedLanguagePageTranslationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    private SiteFinder $siteFinder;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records without corresponding page translation');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Translated records need a corresponding page translation on their pid to be valid.',
            'When a page translation for a given language does not exist, content records translated',
            'to that language are orphaned. This check finds and removes such records. Only languages',
            'configured in the site configuration are considered.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        if (!$tableHelper->tableExistsInDatabase('pages')) {
            // pages table is required for page translation lookups.
            return [];
        }

        /** @var array<int, Site|false> $siteCache */
        $siteCache = [];
        /** @var array<string, array<int, true>> $siteLanguageCache */
        $siteLanguageCache = [];
        /** @var array<int, array<int, true>> $pageTranslationCache */
        $pageTranslationCache = [];

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            if (!$tableHelper->tableExistsInDatabase($tableName)) {
                // TCA may define tables not yet present in database schema.
                continue;
            }

            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);

            $selectFields = [
                'uid',
                'pid',
                $languageField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
                $selectFields[] = 't3ver_state';
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not consider already deleted records: Those are not visible and will not cause
            // issues. Reducing the number of affected records avoids unnecessary noise.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder
                ->select(...$selectFields)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq($languageField, $queryBuilder->createNamedParameter(-1, Connection::PARAM_INT))
                )
                ->orderBy('uid');

            if ($isTableWorkspaceAware) {
                // Skip DELETE_PLACEHOLDER records (t3ver_state = 2), those are workspace internals.
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->neq('t3ver_state', $queryBuilder->createNamedParameter(2, Connection::PARAM_INT))
                );
            }

            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $pid = (int)$row['pid'];
                $langId = (int)$row[$languageField];

                // Resolve site for this pid, cached. Records on pages without site config
                // (e.g. pid 0 or pages not below a site root) are skipped: No site means
                // no language configuration to validate against.
                if (!array_key_exists($pid, $siteCache)) {
                    try {
                        $siteCache[$pid] = $this->siteFinder->getSiteByPageId($pid);
                    } catch (SiteNotFoundException) {
                        $siteCache[$pid] = false;
                    }
                }
                if ($siteCache[$pid] === false) {
                    continue;
                }
                $site = $siteCache[$pid];

                // Only check languages that are configured in the site. Records with a language
                // not in site config are handled by TcaTablesTranslatedLanguageNotInSiteConfiguration.
                $siteIdentifier = $site->getIdentifier();
                if (!isset($siteLanguageCache[$siteIdentifier])) {
                    $siteLanguageCache[$siteIdentifier] = [];
                    foreach ($site->getAllLanguages() as $siteLanguage) {
                        $siteLanguageCache[$siteIdentifier][$siteLanguage->getLanguageId()] = true;
                    }
                }
                if (!isset($siteLanguageCache[$siteIdentifier][$langId])) {
                    continue;
                }

                // Check if a page translation exists for this pid and language, cached.
                // Do not consider deleted page translations: A deleted page translation means
                // content translations on that page are orphaned and should be removed.
                if (!isset($pageTranslationCache[$pid])) {
                    $pageTranslationCache[$pid] = [];
                    $pageQueryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
                    $pageQueryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                    $pageResult = $pageQueryBuilder
                        ->select('sys_language_uid')
                        ->from('pages')
                        ->where(
                            $pageQueryBuilder->expr()->eq('l10n_parent', $pageQueryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                            $pageQueryBuilder->expr()->gt('sys_language_uid', $pageQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                        )
                        ->executeQuery();
                    while ($pageRow = $pageResult->fetchAssociative()) {
                        $pageTranslationCache[$pid][(int)$pageRow['sys_language_uid']] = true;
                    }
                }
                if (!isset($pageTranslationCache[$pid][$langId])) {
                    $row['_reasonBroken'] = 'MissingPageTranslation';
                    $affectedRows[$tableName][(int)$row['uid']] = $row;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '_reasonBroken');
    }
}
