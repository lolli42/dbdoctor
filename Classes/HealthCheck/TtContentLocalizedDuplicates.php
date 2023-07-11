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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * There must be only one localized (l18n_parent) record per sys_language_uid.
 *
 * @todo: This ignores workspaces for now, which needs more mind boggling things with further checks.
 *        Also, we may want to have something similar for non-tt_content tables.
 */
final class TtContentLocalizedDuplicates extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Duplicate localized tt_content records');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'There must be only one localized record in "tt_content" per target language.',
            'Having more than one leads to various issues in FE and BE. This check finds',
            'duplicates, keeps the one with the lowest uid and soft-deletes others.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Ignore deleted=1 records
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent')->from('tt_content')
            ->where(
                // Ignore workspace records
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
                // Localized
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                // "Connected" mode
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('pid')
            ->executeQuery();
        // First build a map of all localized records per sys_language_uid and l18n_parent
        $candidates = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $languageUid = (int)$row['sys_language_uid'];
            if (!array_key_exists($languageUid, $candidates)) {
                $candidates[$languageUid] = [];
            }
            $l18nParent = (int)$row['l18n_parent'];
            if (!array_key_exists($l18nParent, $candidates[$languageUid])) {
                $candidates[$languageUid][$l18nParent] = [];
            }
            $uid = (int)$row['uid'];
            $record = [
                'uid' => $uid,
                'pid' => (int)$row['pid'],
            ];
            $candidates[$languageUid][$l18nParent][$uid] = $record;
        }
        $affectedRecords = [];
        foreach ($candidates as $l18nParents) {
            foreach ($l18nParents as $localizations) {
                if (count($localizations) > 1) {
                    // Sort existing localizations by uid, so we have lowest uid first.
                    ksort($localizations);
                    // The first one with lowest uid should be kept, so remove it here.
                    array_shift($localizations);
                    foreach ($localizations as $localization) {
                        // The others are target of soft-deletion.
                        $affectedRecords['tt_content'][] = $localization;
                    }
                }
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $updateFields = [
            'deleted' => [
                'value' => 1,
                'type' => \PDO::PARAM_INT,
            ],
        ];
        $this->updateTcaRecordsOfTable($io, $simulate, 'tt_content', $affectedRecords['tt_content'] ?? [], $updateFields);
    }
}
