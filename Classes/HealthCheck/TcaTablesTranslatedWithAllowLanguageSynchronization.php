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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle translated records where the field has allowLanguageSynchronization=1, the l10n_state has "Value of default
 * language" but the value differs from record of default language.
 */
final readonly class TcaTablesTranslatedWithAllowLanguageSynchronization extends AbstractHealthCheck implements HealthCheckInterface
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
            'it is not overwritten when the default language record is changed. Fields of TCA type',
            '"json" are not compared. In relation fields, NULL, empty string and "0" are equal: They',
            'all mean "no relation".',
        ]);
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            $fieldNames = State::getFieldNames($tableName);
            if ($fieldNames === []
                || !$this->tableHelper->tableExistsInDatabase($tableName)
                || !$this->tableHelper->fieldExistsInTable($tableName, 'l10n_state')
            ) {
                continue;
            }
            // Fields with allowLanguageSynchronization may have no database column, e.g. type "none".
            $fieldNames = array_values(array_filter(
                $fieldNames,
                fn(string $fieldName): bool => $this->tableHelper->fieldExistsInTable($tableName, $fieldName)
            ));
            // Fields of TCA type "json" are not compared: Postgres has no "<>" operator for column
            // type json, and equal json values may differ as text, like {"a":1} and {"a": 1}.
            $fieldNames = array_values(array_filter(
                $fieldNames,
                static fn(string $fieldName): bool => ($GLOBALS['TCA'][$tableName]['columns'][$fieldName]['config']['type'] ?? '') !== 'json'
            ));
            if ($fieldNames === []) {
                continue;
            }
            // NULL, '' and '0' all mean "no relation" in relation fields: DataHandler writes '', '0' is found
            // in records written without DataHandler, for instance by imports or the styleguide generator.
            $relationFieldNames = array_flip(array_filter(
                $fieldNames,
                fn(string $fieldName): bool => $this->isRelationField($tableName, $fieldName)
            ));
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
                // Null safe "not equal". It follows the column collation: Case insensitive
                // collations of mysql and mariadb miss values differing only in case or accents.
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
                    $isRelationField = isset($relationFieldNames[$fieldName]);
                    if ($state?->isParentState($fieldName)
                        && $this->normalizeValue($row[$fieldName], $isRelationField) !== $this->normalizeValue($row['_parent_' . $fieldName], $isRelationField)
                    ) {
                        $affectedFieldNames[] = $fieldName;
                    }
                }
                if ($affectedFieldNames === []) {
                    continue;
                }
                // Change only the affected fields and keep the rest of l10n_state as is: Fields
                // without state are "parent" by default, see State->enrich().
                $states = is_array($l10nState) ? $l10nState : [];
                foreach ($affectedFieldNames as $fieldName) {
                    $states[$fieldName] = State::STATE_CUSTOM;
                }
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$row['uid']),
                    (int)$row['pid'],
                    [
                        new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], [
                            'l10n_state' => [
                                'value' => (string)json_encode($states),
                                'type' => Connection::PARAM_STR,
                            ],
                        ]),
                    ],
                    new Reason(
                        'value-differs',
                        'Value differs from default language: ' . implode(', ', $affectedFieldNames),
                        ['fieldNames' => $affectedFieldNames]
                    ),
                );
            }
        }
        return $findings;
    }

    protected function getDetailCtrlFields(string $tableName): array
    {
        return ['languageField', 'transOrigPointerField'];
    }

    protected function getDetailDbFields(string $tableName): array
    {
        return ['l10n_state'];
    }

    /**
     * Database drivers return int columns as int or string: Compare as string, keep null.
     * Relation fields without relation are an empty string.
     */
    private function normalizeValue(int|string|null $value, bool $isRelationField): ?string
    {
        if ($isRelationField && ($value === null || (string)$value === '' || (string)$value === '0')) {
            return '';
        }
        return $value === null ? null : (string)$value;
    }

    /**
     * Fields of type "group" and "category", and fields of type "select" with "foreign_table".
     */
    private function isRelationField(string $tableName, string $fieldName): bool
    {
        $config = $GLOBALS['TCA'][$tableName]['columns'][$fieldName]['config'] ?? [];
        $type = $config['type'] ?? '';
        return $type === 'group'
            || $type === 'category'
            || ($type === 'select' && !empty($config['foreign_table']));
    }
}
