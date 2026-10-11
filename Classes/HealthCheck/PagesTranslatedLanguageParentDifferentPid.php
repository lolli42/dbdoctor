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
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Find translated pages that are on a different pid than their no sys_language_uid=0 parent.
 */
final readonly class PagesTranslatedLanguageParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check pages with different pid than their language parent',
            [self::TAG_REMOVE],
            [
                'This health check finds translated "pages" records (sys_language_uid > 0) with',
                'their default language record (l10n_parent field) on a different pid.',
                'Those translated pages are shown in backend at a wrong place. They are removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Deleted pages are considered as well, we remove all restrictions.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid', 't3ver_state', 'l10n_parent', 'sys_language_uid')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                /** @var array<string, int|string> $languageParentRow */
                $languageParentRow = $this->recordsHelper->getRecord($run->statements, 'pages', ['uid', 'pid'], (int)$row['l10n_parent']);
                if ((int)$row['pid'] !== (int)$languageParentRow['pid']
                    // Ignore "workspace moved" translations due to the odd l10n_parent behavior, as
                    // shown with the tests from https://review.typo3.org/c/Packages/TYPO3.CMS/+/89803
                    // @todo: This could be optimized when the underlying core question has been decided.
                    && !((int)$row['t3ver_wsid'] > 0 && (int)$row['t3ver_state'] === 4)
                ) {
                    $findings[] = new Finding(
                        new RecordKey('pages', (int)$row['uid']),
                        (int)$row['pid'],
                        [new RecordChange(RecordChangeType::Delete, 'pages', (int)$row['uid'])],
                        new Reason('parent-different-pid', 'Language parent "' . $row['l10n_parent'] . '" is on pid "' . $languageParentRow['pid'] . '"', ['parent' => (int)$row['l10n_parent'], 'parentPid' => (int)$languageParentRow['pid']]),
                    );
                }
            } catch (NoSuchRecordException $e) {
                // Earlier test should have fixed this.
                throw new EarlierCheckNotFixedException(
                    'Pages record with uid="' . $row['uid'] . '" and sys_language_uid="' . $row['sys_language_uid'] . '"'
                    . ' has l10n_parent="' . $row['l10n_parent'] . '", but that record does not exist.'
                    . ' An earlier check finds and fixes this.',
                    1647793649
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
