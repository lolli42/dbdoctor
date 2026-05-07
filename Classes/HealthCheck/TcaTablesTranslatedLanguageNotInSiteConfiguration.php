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
 * Translated records should reference a sys_language_uid that is configured in the
 * site configuration of the page they are located on. Records with a language not
 * in the site configuration are orphaned translations typically created by DataHandler
 * copy operations between sites.
 */
final class TcaTablesTranslatedLanguageNotInSiteConfiguration extends AbstractHealthCheck implements HealthCheckInterface
{
    private SiteFinder $siteFinder;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with language not in site configuration');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Translated records reference a sys_language_uid. This language must be configured',
            'in the site configuration of the page they are located on. This check finds records',
            'with a sys_language_uid that does not exist in the site configuration and removes them.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        /** @var array<int, Site|false> $siteCache */
        $siteCache = [];
        /** @var array<string, array<int, true>> $siteLanguageCache */
        $siteLanguageCache = [];

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
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

                $siteIdentifier = $site->getIdentifier();
                if (!isset($siteLanguageCache[$siteIdentifier])) {
                    $siteLanguageCache[$siteIdentifier] = [];
                    foreach ($site->getAllLanguages() as $siteLanguage) {
                        $siteLanguageCache[$siteIdentifier][$siteLanguage->getLanguageId()] = true;
                    }
                }

                if (!isset($siteLanguageCache[$siteIdentifier][$langId])) {
                    $row['_reasonBroken'] = 'LanguageNotInSiteConfiguration';
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
