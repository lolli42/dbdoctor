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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * tt_content l10n_source must point to an existing record.
 */
final class TtContentLocalizationSourceExists extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Localized tt_content records must point to existing localization source');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'When l10n_source is not zero, the target record must exist.',
            'A broken l10n_source especially confuses the "Translate" button in page module.',
            'Affected records l10n_source is set to l18n_parent if set, to zero otherwise.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Handle deleted=1 records, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent', 'l10n_source')->from('tt_content')
            ->where(
                // sys_language_uid=0 records are zero already due to TcaTablesLanguageLessThanOneHasZeroLanguageSource
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l10n_source', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $recordsHelper->getRecord('tt_content', ['uid'], (int)$row['l10n_source']);
            } catch (NoSuchRecordException $e) {
                $affectedRecords['tt_content'][] = $row;
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $this->outputTableUpdateBefore($io, $simulate, 'tt_content');
        $count = 0;
        foreach (($affectedRecords['tt_content'] ?? []) as $row) {
            $newLocalizationSource = 0;
            $localizationParent = (int)$row['l18n_parent'];
            if ($localizationParent > 0) {
                $newLocalizationSource = $localizationParent;
            }
            $updateFields = [
                'l10n_source' => [
                    'value' => $newLocalizationSource,
                    'type' => \PDO::PARAM_INT,
                ],
            ];
            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, 'tt_content', (int)$row['uid'], $updateFields);
            $count++;
        }
        $this->outputTableUpdateAfter($io, $simulate, 'tt_content', $count);
    }
}
