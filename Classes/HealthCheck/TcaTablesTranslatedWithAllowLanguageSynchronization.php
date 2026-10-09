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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle translated records where the field has allowLanguageSynchronization=1, the l10n_state has "Value of default
 * language" but the value differs from record of default language.
 */
final class TcaTablesTranslatedWithAllowLanguageSynchronization extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with values not in sync with default language');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'Fields with TCA "allowLanguageSynchronization" can use the value of the default language',
            'record in translations, the database field "l10n_state" stores this per field. This check',
            'finds live translations with l10n_state "parent" for a field, but a value different from',
            'the default language record. The frontend renders the value of the translation, so the',
            'l10n_state of such fields is set to "custom": The backend then shows the value as well, and',
            'it is not overwritten when the default language record is changed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            $fieldNames = State::getFieldNames($tableName);
            if ($fieldNames === []
                || !$tableHelper->tableExistsInDatabase($tableName)
                || !$tableHelper->fieldExistsInTable($tableName, 'l10n_state')
            ) {
                continue;
            }
            // Fields with allowLanguageSynchronization may have no database column, e.g. type "none".
            $fieldNames = array_values(array_filter(
                $fieldNames,
                static fn(string $fieldName): bool => $tableHelper->fieldExistsInTable($tableName, $fieldName)
            ));
            if ($fieldNames === []) {
                continue;
            }
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not consider soft-deleted records
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $expr = $queryBuilder->expr();
            $selectFields = [
                'translation.uid',
                'translation.pid',
                'translation.' . $languageField,
                'translation.' . $translationParentField,
                'translation.l10n_state',
            ];
            $differentValueConstraints = [];
            foreach ($fieldNames as $fieldName) {
                $translationField = 'translation.' . $fieldName;
                $parentField = 'parent.' . $fieldName;
                $selectFields[] = $translationField;
                $selectFields[] = $parentField . ' AS _parent_' . $fieldName;
                // Null safe "not equal"
                $differentValueConstraints[] = $expr->or(
                    $expr->neq($translationField, $queryBuilder->quoteIdentifier($parentField)),
                    $expr->and($expr->isNull($translationField), $expr->isNotNull($parentField)),
                    $expr->and($expr->isNotNull($translationField), $expr->isNull($parentField)),
                );
            }
            $queryBuilder->select(...$selectFields)
                ->from($tableName, 'translation')
                ->join(
                    'translation',
                    $tableName,
                    'parent',
                    $expr->eq('translation.' . $translationParentField, $queryBuilder->quoteIdentifier('parent.uid'))
                )
                ->where(
                    $expr->gt('translation.' . $languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $expr->or(...$differentValueConstraints),
                )
                ->orderBy('translation.uid');
            if ($workspaceIdField) {
                // Live records only: Workspace records would need to be compared to the
                // workspace version of their default language record.
                $queryBuilder->andWhere(
                    $expr->eq('translation.' . $workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $expr->eq('parent.' . $workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                );
            }
            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $row */
                // A JSON scalar like '"x"' would make State throw: Handle it like invalid JSON, which
                // core treats as no state, all fields are "parent".
                $l10nState = json_decode((string)$row['l10n_state'], true);
                $state = State::fromJSON($tableName, is_array($l10nState) ? (string)$row['l10n_state'] : null);
                $affectedFieldNames = [];
                foreach ($fieldNames as $fieldName) {
                    if ($state?->isParentState($fieldName)
                        && $this->normalizeValue($row[$fieldName]) !== $this->normalizeValue($row['_parent_' . $fieldName])
                    ) {
                        $affectedFieldNames[] = $fieldName;
                    }
                }
                if ($affectedFieldNames === []) {
                    continue;
                }
                $affectedRows[$tableName][] = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'l10n_state' => (string)$row['l10n_state'],
                    '_fieldNames' => implode(',', $affectedFieldNames),
                    '_reasonBroken' => 'Value differs from default language: ' . implode(', ', $affectedFieldNames),
                ];
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $rows) {
            $this->outputTableUpdateBefore($io, $simulate, $tableName);
            foreach ($rows as $row) {
                // Change only the affected fields and keep the rest of l10n_state as is: Fields
                // without state are "parent" by default, see State->enrich().
                $states = json_decode((string)$row['l10n_state'], true);
                $states = is_array($states) ? $states : [];
                foreach (explode(',', (string)$row['_fieldNames']) as $fieldName) {
                    $states[$fieldName] = State::STATE_CUSTOM;
                }
                $fields = [
                    'l10n_state' => [
                        'value' => (string)json_encode($states),
                        'type' => Connection::PARAM_STR,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $fields);
            }
            $this->outputTableUpdateAfter($io, $simulate, $tableName, count($rows));
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '_reasonBroken', ['languageField', 'transOrigPointerField'], ['l10n_state']);
    }

    /**
     * Database drivers return int columns as int or string: Compare as string, keep null.
     */
    private function normalizeValue(int|string|null $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
