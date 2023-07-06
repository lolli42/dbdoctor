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

use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * sys_redirect records should be on pid 0, or pids having a site config.
 * This was improved with v12, however, we need something for v11 as well:
 * sys_redirects records with deleted=0 on pages that are deleted=1 or
 * don't exist, still match! Since we have other checks that set records
 * deleted=1 if they are on pages with deleted=1, we need to move those
 * away, first.
 * This check is similar to the core upgrade wizard SysRedirectRootPageMoveMigration.
 */
final class SysRedirectInvalidPid extends AbstractHealthCheck implements HealthCheckInterface
{
    private SiteFinder $siteFinder;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_redirect records on wrong pid');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'Redirect records should be located on pages having a site config, or pid 0.',
            'There is a TYPO3 core v12 upgrade wizard to deal with this. This check takes',
            'care of affected records as well: Records on pages that have no site config',
            'are moved to the first page up in rootline that has a site config, or to pid 0.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        if (!$tableHelper->tableExistsInDatabase('sys_redirect')) {
            return [];
        }

        $allowedPids = [];
        $allowedPids[] = 0;
        $sites = $this->siteFinder->getAllSites(false);
        foreach ($sites as $site) {
            $allowedPids[] = $site->getRootPageId();
        }
        $allowedPids = array_unique($allowedPids);

        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        // We for now don't take care of deleted=1 records in this case to reduce the number of affected records
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid')->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->notIn('pid', $allowedPids)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $tableRows['sys_redirect'][] = $row;
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->outputTableUpdateBefore($io, $simulate, $tableName);
            $count = 0;
            foreach ($tableRows as $tableRow) {
                try {
                    // Note this "pollutes" rootline caches
                    $rootPageId = $this->siteFinder->getSiteByPageId((int)$tableRow['pid'])->getRootPageId();
                } catch (SiteNotFoundException $e) {
                    // Move redirects without proper site config root page to pid 0.
                    $rootPageId = 0;
                }
                $updateFields = [
                    'pid' => [
                        'value' => $rootPageId,
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$tableRow['uid'], $updateFields);
                $count ++;
            }
            $this->outputTableUpdateAfter($io, $simulate, $tableName, $count);
        }
    }
}
