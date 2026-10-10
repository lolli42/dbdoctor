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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Records in localization tables must point to a sys_language_uid=0 record in their transOrigPointerField.
 *
 * There is no table specific variant for tt_content or sys_file_reference: This check runs
 * before their checks and covers them. pages has its own checks and is excluded.
 */
final class TcaTablesTranslatedParentInvalidPointer extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations pointing to non default language parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            'database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").',
            'This field points to the default language record. This health check verifies that target',
            'actually has sys_language_uid = 0. Violating localizations are set to the transOrigPointerField',
            'of the current target record. Localizations of a sys_language_uid = -1 record are soft deleted',
            'if possible, or removed: The "all languages" record is shown in their language already. Inline',
            'children of a translated parent record are an exception: The frontend shows the children of the',
            'translated parent, not the "all languages" child of the default parent. They are set to free mode.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $inlineChildTables = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChildTable) {
            $inlineChildTables[$inlineChildTable['tableName']] = $inlineChildTable;
        }
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldChildTcaTable() as $inlineChildTable) {
            $inlineChildTables[$inlineChildTable['tableName']] ??= $inlineChildTable;
        }

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $selectFields = ['uid', 'pid', $translationParentField];
            if ($workspaceIdField) {
                $selectFields[] = $workspaceIdField;
            }

            $parentRowFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // Query could be potentially optimized with a self-join, but well ...
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // in 'connected' mode
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // does not point to itself. This is a sanitation for this check and should have been fixed by
                    // PagesTranslatedLanguageParentSelf and TcaTablesTranslatedParentSelf already, but could pop
                    // up here again if the check is run multiple times.
                    $queryBuilder->expr()->neq($tableName . '.uid', $tableName . '.' . $translationParentField)
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $this->recordsHelper->getRecord($run->statements, $tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow[$languageField] !== 0
                        // Skip record if the parent row has l10n_parent=uid
                        && (int)$parentRow[$translationParentField] !== (int)$parentRow['uid']
                    ) {
                        $localizedRow['_parentRowLanguage'] = (int)$parentRow[$languageField];
                        $localizedRow['_reasonBroken'] = 'Parent record language ' . (int)$parentRow[$languageField];
                        $localizedRow['_childOfTranslatedRecord'] = 0;
                        if ((int)$parentRow[$languageField] < 0
                            && isset($inlineChildTables[$tableName])
                            && $this->isChildOfTranslatedRecord($run, $inlineChildTables[$tableName], (int)$localizedRow['uid'])
                        ) {
                            $localizedRow['_childOfTranslatedRecord'] = 1;
                            $localizedRow['_reasonBroken'] .= ', inline child of a translated record';
                        }
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

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $affectedTableRecords) {
            // Localizations of an "all languages" record are obsolete, the -1 record is shown in their language.
            // Not for inline children of a translated record: The frontend shows the children of the translated
            // record, the "all languages" child is attached to the default language record. They are kept.
            $allLanguagesParentRows = array_filter(
                $affectedTableRecords,
                static fn(array $row): bool => (int)$row['_parentRowLanguage'] < 0 && (int)$row['_childOfTranslatedRecord'] === 0
            );
            if ($allLanguagesParentRows !== []) {
                $this->softOrHardDeleteRecordsOfTable($run, $simulate, $tableName, array_values($allLanguagesParentRows));
            }
            $otherRows = array_filter(
                $affectedTableRecords,
                static fn(array $row): bool => (int)$row['_parentRowLanguage'] >= 0 || (int)$row['_childOfTranslatedRecord'] === 1
            );
            foreach ($otherRows as $affectedTableRecord) {
                /** @var string $translationParentField */
                $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
                $parentRow = $this->recordsHelper->getRecord($run->statements, $tableName, ['uid', $translationParentField], (int)$affectedTableRecord[$translationParentField]);
                $fields = [
                    $translationParentField => [
                        'value' => (int)$parentRow[$translationParentField],
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($run, $simulate, $tableName, (int)$affectedTableRecord['uid'], $fields);
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '_reasonBroken', ['languageField', 'transOrigPointerField']);
    }

    /**
     * True if the inline parent record of a child is a translated record (TCA "languageField" > 0).
     *
     * @param array<string, string> $inlineChildTable
     */
    private function isChildOfTranslatedRecord(HealthCheckRun $run, array $inlineChildTable, int $uid): bool
    {
        $fields = [$inlineChildTable['fieldNameOfParentTableUid']];
        if (isset($inlineChildTable['fieldNameOfParentTableName'])) {
            $fields[] = $inlineChildTable['fieldNameOfParentTableName'];
        }
        $childRow = $this->recordsHelper->getRecord($run->statements, $inlineChildTable['tableName'], $fields, $uid);
        $parentTableName = isset($inlineChildTable['fieldNameOfParentTableName'])
            ? (string)$childRow[$inlineChildTable['fieldNameOfParentTableName']]
            : $inlineChildTable['parentTableName'];
        $parentLanguageField = $this->tcaHelper->getLanguageField($parentTableName);
        if ($parentLanguageField === null) {
            return false;
        }
        try {
            $parentRow = $this->recordsHelper->getRecord($run->statements, $parentTableName, [$parentLanguageField], (int)$childRow[$inlineChildTable['fieldNameOfParentTableUid']]);
        } catch (NoSuchRecordException $e) {
            // Missing inline parent: Handled by later inline checks.
            return false;
        }
        return (int)$parentRow[$parentLanguageField] > 0;
    }
}
