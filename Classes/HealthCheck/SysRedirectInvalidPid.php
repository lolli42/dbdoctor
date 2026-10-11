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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Exception\Page\CircularRootLineException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * sys_redirect records should be on pid 0, or pids having a site config.
 * sys_redirects records with deleted=0 on pages that are deleted=1 or
 * don't exist, still match! Since we have other checks that set records
 * deleted=1 if they are on pages with deleted=1, we need to move those
 * away, first.
 * This check is similar to the core upgrade wizard SysRedirectRootPageMoveMigration.
 */
final readonly class SysRedirectInvalidPid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for sys_redirect records on wrong pid',
            [ActionTag::UpdateFields],
            [
                'Redirect records should be located on pages having a site config, or pid 0.',
                'A TYPO3 core upgrade wizard introduced in v12 deals with this. This check takes',
                'care of affected records as well: Records on pages that have no site config',
                'are moved to the first page up in rootline that has a site config, or to pid 0.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        if (!$this->tableHelper->tableExistsInDatabase('sys_redirect')) {
            return [];
        }

        $allowedPids = [];
        $allowedPids[] = 0;
        $sites = $this->siteFinder->getAllSites(false);
        foreach ($sites as $site) {
            $allowedPids[] = $site->getRootPageId();
        }
        $allowedPids = array_unique($allowedPids);

        $findings = [];
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
            $rootPageId = $this->getRootPageId((int)$row['pid']);
            $findings[] = new Finding(
                new RecordKey('sys_redirect', (int)$row['uid']),
                (int)$row['pid'],
                [
                    new RecordChange(RecordChangeType::Update, 'sys_redirect', (int)$row['uid'], [
                        'pid' => [
                            'value' => $rootPageId,
                            'type' => Connection::PARAM_INT,
                        ],
                    ]),
                ],
                new Reason(
                    'no-site-root',
                    'Page "' . $row['pid'] . '" is no site root, target "' . $rootPageId . '"',
                    ['target' => $rootPageId]
                ),
            );
        }
        return $findings;
    }

    /**
     * First page up in rootline that has a site config, or 0.
     */
    private function getRootPageId(int $pid): int
    {
        try {
            // Note this "pollutes" rootline caches
            return $this->siteFinder->getSiteByPageId($pid)->getRootPageId();
        } catch (SiteNotFoundException $e) {
            // Move redirects without proper site config root page to pid 0.
            return 0;
        } catch (CircularRootLineException $e) {
            // A pid loop has no site root either, PagesBrokenTree removes these pages later.
            return 0;
        }
    }
}
