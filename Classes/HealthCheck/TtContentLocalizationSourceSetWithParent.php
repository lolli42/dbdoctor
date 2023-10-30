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

/**
 * tt_content l10n_source must be set to something if l18n_parent is not zero.
 */
final class TtContentLocalizationSourceSetWithParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Localized tt_content records must have localization source when parent is set');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'When l18n_parent is not zero ("Connected mode"), l10n_source must not be zero.',
            'A broken l10n_source especially confuses the "Translate" button in page module.',
            'Affected records l10n_source is set to l18n_parent.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Handle deleted=1 records, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent', 'l10n_source')->from('tt_content')
            ->where(
                // sys_language_uid=0 records are zero already due to TcaTablesLanguageLessThanOneHasZeroLanguageSource
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->eq('l10n_source', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $affectedRecords['tt_content'][] = $row;
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
            $updateFields = [
                'l10n_source' => [
                    'value' => (int)$row['l18n_parent'],
                    'type' => \PDO::PARAM_INT,
                ],
            ];
            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, 'tt_content', (int)$row['uid'], $updateFields);
            $count++;
        }
        $this->outputTableUpdateAfter($io, $simulate, 'tt_content', $count);
    }
}
