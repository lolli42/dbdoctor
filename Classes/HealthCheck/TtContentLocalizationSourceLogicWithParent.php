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
use TYPO3\CMS\Core\Database\Connection;

/**
 * tt_content with l18n_parent > 0 and l10n_source > 0 and both values different.
 * In this case the l18n_parent of the uid l10n_source points to, must match with
 * l18n_parent of handled record.
 */
final class TtContentLocalizationSourceLogicWithParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Localized tt_content records must have logically correct localization source');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'When tt_content l18n_parent and l10n_source are not zero but point to different uids,',
            'it indicates this record "source" has been derived from a different language record',
            'and not from the default language record. That different language record should have the',
            'same l18n_parent. If this is not the case, set the tt_content l10n_source to the',
            'value of l18n_parent to fix the inheritance chain.',
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
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l10n_source', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0),
                // `l10n_source` != `l18n_parent`
                $queryBuilder->expr()->neq('l10n_source', $queryBuilder->quoteIdentifier('l18n_parent'))
            )
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            // Get record l10n_source point to
            $localizationSourceRecord = $recordsHelper->getRecord('tt_content', ['uid', 'l18n_parent'], (int)$row['l10n_source']);
            // Compare both l18n_parent - they must be the same uid, otherwise l10n_source of
            // $row is broken and should be set to the value of l18n_parent.
            if ((int)$localizationSourceRecord['l18n_parent'] !== (int)$row['l18n_parent']) {
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
            $updateFields = [
                'l10n_source' => [
                    'value' => (int)$row['l18n_parent'],
                    'type' => Connection::PARAM_INT,
                ],
            ];
            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, 'tt_content', (int)$row['uid'], $updateFields);
            $count++;
        }
        $this->outputTableUpdateAfter($io, $simulate, 'tt_content', $count);
    }
}
