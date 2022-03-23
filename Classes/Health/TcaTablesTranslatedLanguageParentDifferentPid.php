<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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
 * Tables with record translations must have their pid set to the same pid the default language record points to
 */
class TcaTablesTranslatedLanguageParentDifferentPid extends AbstractHealth implements HealthInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations on wrong pid');
        $io->text([
            '[UPDATE] Record translations use the TCA ctrl field "transOrigPointerField"',
            '(DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
            'default language record. This health check verifies translated records are on',
            'the same pid as the default language record. It will move affected records, or',
            'set them to deleted or remove them if there is another translation of that record',
            'on the correct pid.',
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

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not fetch deleted=1 records if table is soft-delete aware.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select('uid', 'pid', $languageField, $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, ['uid', 'pid'], (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow['pid'] !== (int)$localizedRow['pid']) {
                        $affectedRows[$tableName][] = $localizedRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new \RuntimeException(
                        'Record with uid="' . $localizedRow['uid'] . '" on table "' . $tableName . '"'
                        . ' has ' . $translationParentField . '="' . $localizedRow[$translationParentField] . '", but that record does not exist.'
                        . ' A previous check should have found and fixed this. Please repeat.',
                        1648031986
                    );
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
        foreach ($affectedRecords as $tableName => $tableRows) {
            $deletedField = $tcaHelper->getDeletedField($tableName);
            $hiddenField = $tcaHelper->getHiddenField($tableName);
            /** @var string $languageField */
            $languageField = $tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $tcaHelper->getTranslationParentField($tableName);
            foreach ($tableRows as $localizedRow) {
                $defaultLanguageRow = $recordsHelper->getRecord($tableName, ['uid', 'pid'], (int)$localizedRow[$translationParentField]);
                // See if there is already a localized row on the correct pid
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $existingLocalizedRow = $queryBuilder
                    ->select('uid')
                    ->from($tableName)
                    ->where(
                        $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($defaultLanguageRow['pid'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($localizedRow[$languageField], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($localizedRow[$translationParentField], \PDO::PARAM_INT))
                    )
                    ->executeQuery()
                    ->fetchAllAssociative();
                if (count($existingLocalizedRow) > 0) {
                    // If there is a localized row already, we set the wrong-pid row to correct pid and set deleted=1 for delete-aware tables, or remove it
                    if ($deletedField) {
                        $updateFields = [
                            'pid' => [
                                'value' => (int)$defaultLanguageRow['pid'],
                                'type' => \PDO::PARAM_INT,
                            ],
                            $deletedField => [
                                'value' => 1,
                                'type' => \PDO::PARAM_INT,
                            ],
                        ];
                        $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                    } else {
                        $tableRow = [$tableName => [$localizedRow]];
                        $this->deleteRecords($io, $simulate, $tableRow);
                    }
                } else {
                    if ($tableName === 'sys_file_reference') {
                        // @todo: Maybe split this special handling to an own check, maybe merge with PagesTranslatedLanguageParentDifferentPid?
                        // pid for sys_file_reference is not *that* important: If it's not correct, the
                        // record translation will still be shown. We simply move those to the correct pid.
                        $updateFields = [
                            'pid' => [
                                'value' => (int)$defaultLanguageRow['pid'],
                                'type' => \PDO::PARAM_INT,
                            ],
                        ];
                        $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                    } else {
                        // For all other tables: Moving this record means it may show up in the FE now. To avoid this,
                        // if the table is 'hidden'-aware, we set the record to hidden and move it. If the table is not
                        // hidden aware but delete-aware, we set the record to deleted=1. Else, we remove the record.
                        if ($hiddenField) {
                            $updateFields = [
                                'pid' => [
                                    'value' => (int)$defaultLanguageRow['pid'],
                                    'type' => \PDO::PARAM_INT,
                                ],
                                $hiddenField => [
                                    'value' => 1,
                                    'type' => \PDO::PARAM_INT,
                                ],
                            ];
                            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                        } elseif ($deletedField) {
                            $updateFields = [
                                'pid' => [
                                    'value' => (int)$defaultLanguageRow['pid'],
                                    'type' => \PDO::PARAM_INT,
                                ],
                                $deletedField => [
                                    'value' => 1,
                                    'type' => \PDO::PARAM_INT,
                                ],
                            ];
                            $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                        } else {
                            $tableRow = [$tableName => [$localizedRow]];
                            $this->deleteRecords($io, $simulate, $tableRow);
                        }
                    }
                }
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
