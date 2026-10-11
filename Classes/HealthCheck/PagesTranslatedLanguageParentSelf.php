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
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Find localized pages that point to itself in l10n_parent.
 */
final readonly class PagesTranslatedLanguageParentSelf extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check localized pages having language parent set to self',
            [self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE],
            [
                'This health check finds not deleted but localized (sys_language_uid > 0) "pages" records',
                'having their own uid set as their localization parent (l10n_parent = uid).',
                'This is invalid, such page records are not listed in the BE list module and the Frontend',
                'will most likely not render such pages.',
                'They are soft-deleted in live and removed if they are workspace overlay records.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Do not consider page translation records that have been set to deleted already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'deleted', 'sys_language_uid', 'l10n_parent', 't3ver_wsid')
            ->from('pages')
            ->where(
                // sys_language_uid != 0
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // AND uid = l10n_parent
                $queryBuilder->expr()->eq('pages.uid', 'pages.l10n_parent')
            )
            ->orderBy('uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $findings[] = new Finding(
                new RecordKey('pages', (int)$row['uid']),
                (int)$row['pid'],
                [$this->softOrHardDeleteChange('pages', $row)],
            );
        }
        return $findings;
    }

    public function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }
}
