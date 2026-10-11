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
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Find not-deleted translated pages that have a sys_language_uid=0 parent set to deleted=1.
 */
final readonly class PagesTranslatedLanguageParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check pages with deleted language parent',
            [self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE],
            [
                'This health check finds not deleted but translated (sys_language_uid > 0) "pages" records,',
                'with their default language record (l10n_parent field) being soft-deleted.',
                'Those translated pages are never shown in backend and frontend. They are soft-deleted in',
                'live and removed if they are workspace overlay records.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Do not consider page translation records that have been set to deleted already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'deleted', 'sys_language_uid', 'l10n_parent', 't3ver_wsid')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $parentRecord = $this->recordsHelper->getRecord($run->statements, 'pages', ['uid', 'deleted'], (int)$row['l10n_parent']);
                if ((bool)$parentRecord['deleted']) {
                    $findings[] = new Finding(
                        new RecordKey('pages', (int)$row['uid']),
                        (int)$row['pid'],
                        [$this->softOrHardDeleteChange('pages', $row)],
                        new Reason('parent-deleted', 'Language parent "' . $row['l10n_parent'] . '" is soft-deleted', ['l10n_parent' => (int)$row['l10n_parent']]),
                    );
                }
            } catch (NoSuchRecordException $e) {
                // Earlier test should have fixed this.
                throw new EarlierCheckNotFixedException(
                    'Pages record with uid="' . $row['uid'] . '" and sys_language_uid="' . $row['sys_language_uid'] . '"'
                    . ' has l10n_parent="' . $row['l10n_parent'] . '", but that record does not exist.'
                    . ' An earlier check finds and fixes this.',
                    1647793648
                );
            }
        }
        return $findings;
    }

    protected function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }
}
