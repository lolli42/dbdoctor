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
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Records in localization tables must point to a sys_language_uid=0 record in their transOrigPointerField.
 */
class TcaTablesTranslatedParentInvalidPointer extends AbstractHealthCheck implements HealthCheckInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations pointing to non default language parent');
        $io->text([
            '[UPDATE] Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            '         database field "transOrigPointerField" (DB field name usually "l10n_parent" or "l18n_parent").',
            '         This field points to the default language record if set. This health check verifies if that target',
            '         actually has sys_language_uid = 0. Violating localizations are set to the transOrigPointerField',
            '         of the current target record.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        foreach ($tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $tcaHelper->getTranslationParentField($tableName);

            $parentRowFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // Query could be potentially optimized with a self-join, but well ...
            $result = $queryBuilder->select('uid', 'pid', $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow[$languageField] !== 0) {
                        $affectedRows[$tableName][] = $localizedRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Ignore non-existing localization parent rows for now.
                    continue;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $affectedTableRecords) {
            foreach ($affectedTableRecords as $affectedTableRecord) {
                /** @var string $translationParentField */
                $translationParentField = $tcaHelper->getTranslationParentField($tableName);
                $parentRow = $recordsHelper->getRecord($tableName, ['uid', $translationParentField], (int)$affectedTableRecord[$translationParentField]);
                $fields = [
                    $translationParentField => [
                        'value' => (int)$parentRow[$translationParentField],
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$affectedTableRecord['uid'], $fields);
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['languageField', 'transOrigPointerField']);
    }
}
