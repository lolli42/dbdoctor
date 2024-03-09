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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tables with record translations must have their pid set to the same pid the default language record points to.
 *
 * @todo: needs update to skip tt_content?!
 */
final class TcaTablesTranslatedLanguageParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations on wrong pid');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Record translations use the TCA ctrl field "transOrigPointerField"',
            '(DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
            'default language record. This health check verifies translated records are on',
            'the same pid as the default language record. It will move, hide or remove affected',
            'records, which depends on potentially existing localizations on the target page.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        // @todo: sys_file_reference is excluded due to https://github.com/lolli42/dbdoctor/issues/30, see comment below, too.
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages', 'sys_file_reference']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);

            $selectFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not fetch deleted=1 records if table is soft-delete aware.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
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
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->outputTableHandleBefore($io, $simulate, $tableName);

            $updateCount = 0;
            $deleteCount = 0;

            $deleteField = $this->tcaHelper->getDeletedField($tableName);
            $isTableSoftDeleteAware = !empty($deleteField);
            $hiddenField = $this->tcaHelper->getHiddenField($tableName);
            $isTableHiddenAware = !empty($hiddenField);
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);

            foreach ($tableRows as $localizedRow) {
                if ($isTableWorkspaceAware && !array_key_exists($workspaceIdField, $localizedRow)) {
                    throw new \RuntimeException(
                        'When soft or hard deleting records from a workspace aware table, t3ver_wsid field must be hand over.',
                        1688290136
                    );
                }

                $defaultLanguageRow = $recordsHelper->getRecord($tableName, ['uid', 'pid'], (int)$localizedRow[$translationParentField]);

                // See if there is already a localized row on the correct pid
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder
                    ->select('uid')
                    ->from($tableName)
                    ->where(
                        $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($defaultLanguageRow['pid'], Connection::PARAM_INT)),
                        $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($localizedRow[$languageField], Connection::PARAM_INT)),
                        $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($localizedRow[$translationParentField], Connection::PARAM_INT))
                    );
                if ($isTableWorkspaceAware && $localizedRow[$workspaceIdField] > 0) {
                    // If the localized record that is on the wrong pid is a workspace record, check if there is
                    // a localized live OR this-workspace record on the target pid.
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->or(
                            $queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                            $queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter((int)$localizedRow[$workspaceIdField], Connection::PARAM_INT)),
                        )
                    );
                } elseif ($isTableWorkspaceAware) {
                    // If the localized record that is on the wrong pid is a live record in a workspace aware table,
                    // check for existing localized records in live on the target pid.
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
                }

                $existingLocalizedRow = $queryBuilder->executeQuery()->fetchAllAssociative();

                if (count($existingLocalizedRow) > 0) {
                    // If there is a localized row already, we set the wrong-pid row to correct pid and set deleted=1 for delete-aware tables, or remove it
                    if (!$isTableSoftDeleteAware
                        || ($isTableWorkspaceAware && ((int)$localizedRow[$workspaceIdField] > 0))
                    ) {
                        $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid']);
                        $deleteCount++;
                    } else {
                        $updateFields = [
                            'pid' => [
                                'value' => (int)$defaultLanguageRow['pid'],
                                'type' => Connection::PARAM_INT,
                            ],
                            $deleteField => [
                                'value' => 1,
                                'type' => Connection::PARAM_INT,
                            ],
                        ];
                        $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                        $updateCount++;
                    }
                } else {
                    // @todo: sys_file_reference is excluded due to https://github.com/lolli42/dbdoctor/issues/30, see comment above, too.
                    /*
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
                        $updateCount ++;
                    } else {
                    */
                    // For all other tables: Moving this record means it may show up in the FE now. To avoid this,
                    // if the table is 'hidden'-aware, we set the record to hidden and move it. If the table is not
                    // hidden aware but delete-aware, we set the record to deleted=1. Else, we remove the record.
                    if ($isTableHiddenAware) {
                        $updateFields = [
                            'pid' => [
                                'value' => (int)$defaultLanguageRow['pid'],
                                'type' => Connection::PARAM_INT,
                            ],
                            $hiddenField => [
                                'value' => 1,
                                'type' => Connection::PARAM_INT,
                            ],
                        ];
                        $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                        $updateCount++;
                    } elseif (!$isTableSoftDeleteAware
                        || ($isTableWorkspaceAware && ((int)$localizedRow[$workspaceIdField] > 0))
                    ) {
                        $this->deleteSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid']);
                        $deleteCount++;
                    } else {
                        $updateFields = [
                            'pid' => [
                                'value' => (int)$defaultLanguageRow['pid'],
                                'type' => Connection::PARAM_INT,
                            ],
                            $deleteField => [
                                'value' => 1,
                                'type' => Connection::PARAM_INT,
                            ],
                        ];
                        $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$localizedRow['uid'], $updateFields);
                        $updateCount++;
                    }
                }
            }

            $this->outputTableHandleAfter($io, $simulate, $tableName, $updateCount, $deleteCount);
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
