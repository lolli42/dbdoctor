<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

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

use Lolli\Dbdoctor\Tca\ForeignTableListField;
use Lolli\Dbdoctor\Tca\GroupField;
use Lolli\Dbdoctor\Tca\InlineForeignFieldChild;
use Lolli\Dbdoctor\Tca\InlineForeignFieldNoForeignTableFieldChild;
use Lolli\Dbdoctor\Tca\InlineForeignFieldNoForeignTableFieldParent;
use Lolli\Dbdoctor\Tca\LanguageAwareTable;
use Lolli\Dbdoctor\Tca\LanguageSourceAwareTable;
use Lolli\Dbdoctor\Tca\MmField;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stateless helper service for various TCA details.
 */
final readonly class TcaHelper
{
    /**
     * @return iterable<string>
     * @param array<int, string> $ignoreTables
     */
    public function getNextTcaTable(array $ignoreTables = []): iterable
    {
        $this->verifyTcaIsArray();
        foreach (array_keys($GLOBALS['TCA']) as $tableName) {
            /** @var string $tableName */
            if (in_array($tableName, $ignoreTables, true)) {
                continue;
            }
            yield $tableName;
        }
    }

    /**
     * @return iterable<string>
     */
    public function getNextSoftDeleteAwareTable(): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            if (($config['ctrl']['delete'] ?? false)
                && is_string($config['ctrl']['delete'])
                && !empty($config['ctrl']['delete'])
            ) {
                yield $tableName;
            }
        }
    }

    /**
     * @return iterable<string>
     */
    public function getNextWorkspaceEnabledTcaTable(): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            if ($config['ctrl']['versioningWS'] ?? false) {
                yield $tableName;
            }
        }
    }

    /**
     * We check for *both* languageField (usually sys_language_uid)
     * and transOrigPointerField (usually l10n_parent) here: Only having
     * languageField does not make too much sense and is more a TCA bug
     * than anything else and not handled.
     *
     * @param array<int, string> $ignoreTables
     * @return iterable<LanguageAwareTable>
     */
    public function getNextLanguageAwareTcaTable(array $ignoreTables = []): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            $languageField = $config['ctrl']['languageField'] ?? null;
            $translationParentField = $config['ctrl']['transOrigPointerField'] ?? null;
            if (is_string($languageField) && $languageField !== ''
                && is_string($translationParentField) && $translationParentField !== ''
                && !in_array($tableName, $ignoreTables, true)
            ) {
                yield new LanguageAwareTable($tableName, $languageField, $translationParentField);
            }
        }
    }

    /**
     * We check for languageField (usually sys_language_uid)
     * *and* transOrigPointerField (usually l10n_parent) *and*
     * translationSource (usually l10n_source) here: Not having
     * all three of them does not make too much sense and is more a TCA bug
     * than anything else and not handled.
     *
     * @return iterable<LanguageSourceAwareTable>
     */
    public function getNextLanguageSourceAwareTcaTable(): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            $languageField = $config['ctrl']['languageField'] ?? null;
            $translationParentField = $config['ctrl']['transOrigPointerField'] ?? null;
            $translationSourceField = $config['ctrl']['translationSource'] ?? null;
            if (is_string($languageField) && $languageField !== ''
                && is_string($translationParentField) && $translationParentField !== ''
                && is_string($translationSourceField) && $translationSourceField !== ''
            ) {
                yield new LanguageSourceAwareTable($tableName, $languageField, $translationParentField, $translationSourceField);
            }
        }
    }

    /**
     * 'config' => [
     *      'type' => 'inline',
     *      'foreign_table' => 'tx_sometable_name',
     *      'foreign_field' => 'parent_uid',
     *      'foreign_table_field' => 'parent_table_name',
     * ],
     *
     * @return iterable<InlineForeignFieldChild>
     */
    public function getNextInlineForeignFieldChildTcaTable(): iterable
    {
        $this->verifyTcaIsArray();
        $inlineChildTables = [];
        foreach ($GLOBALS['TCA'] as $config) {
            foreach (($config['columns'] ?? []) as $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    // @todo: skip type=file here - sys_file_reference is already handled with other checks.
                    && in_array(($columnConfig['config']['type'] ?? ''), ['inline', 'file'], true)
                    && (!empty($columnConfig['config']['foreign_table'] ?? ''))
                    && (!empty($columnConfig['config']['foreign_field'] ?? ''))
                    // We require foreign_table_field being set.
                    && (!empty($columnConfig['config']['foreign_table_field'] ?? ''))
                    // Checking existence of TCA definition of the two fields in child table *may* not be strictly required?
                    // We keep it for now, though.
                    && (!empty($GLOBALS['TCA'][$columnConfig['config']['foreign_table']]['columns'][$columnConfig['config']['foreign_field']]))
                    && (!empty($GLOBALS['TCA'][$columnConfig['config']['foreign_table']]['columns'][$columnConfig['config']['foreign_table_field']]))
                    // Note we're not handling the case "two different parents use different foreign_field / foreign_table_field of child":
                    // It's unsure if that works at all. If that happens, first one wins for now.
                    && (!isset($inlineChildTables[$columnConfig['config']['foreign_table']]))
                ) {
                    $inlineChildTables[$columnConfig['config']['foreign_table']] = new InlineForeignFieldChild(
                        $columnConfig['config']['foreign_table'],
                        $columnConfig['config']['foreign_field'],
                        $columnConfig['config']['foreign_table_field'],
                    );
                }
            }
        }
        foreach ($inlineChildTables as $childTable) {
            yield $childTable;
        }
    }

    /**
     * Similar to getNextInlineForeignFieldChildTcaTable(), but without
     * a foreign_table_field in child table.
     *
     *  'config' => [
     *       'type' => 'inline',
     *       'foreign_table' => 'tx_sometable_name',
     *       'foreign_field' => 'parent_uid',
     *  ],
     *
     * @return iterable<InlineForeignFieldNoForeignTableFieldChild>
     */
    public function getNextInlineForeignFieldNoForeignTableFieldChildTcaTable(): iterable
    {
        $this->verifyTcaIsArray();
        $inlineChildTables = [];
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    && in_array(($columnConfig['config']['type'] ?? ''), ['inline'], true)
                    && (!empty($columnConfig['config']['foreign_table'] ?? ''))
                    && (!empty($columnConfig['config']['foreign_field'] ?? ''))
                    // We require foreign_table_field NOT being set.
                    && (!isset($columnConfig['config']['foreign_table_field']))
                    // Checking existence of TCA definition of the foreign_field in child table *may* not be strictly required?
                    // We keep it for now, though.
                    && (!empty($GLOBALS['TCA'][$columnConfig['config']['foreign_table']]['columns'][$columnConfig['config']['foreign_field']]))
                    // Broken TCA: There are 2 parents pointing to the same child, and that child has
                    // no foreign_table_field, If that happens, first one wins for now.
                    // @todo: Core should probably except in TcaPreparation or something?!
                    && (!isset($inlineChildTables[$columnConfig['config']['foreign_table']]))
                ) {
                    $inlineChildTables[$columnConfig['config']['foreign_table']] = new InlineForeignFieldNoForeignTableFieldChild(
                        $columnConfig['config']['foreign_table'],
                        $tableName,
                        $columnConfig['config']['foreign_field'],
                    );
                }
            }
        }
        foreach ($inlineChildTables as $childTable) {
            yield $childTable;
        }
    }

    /**
     * Inline parent fields without foreign_table_field: One entry per parent table, child
     * table, foreign_field and foreign_match_fields combination.
     *
     *  'config' => [
     *       'type' => 'inline',
     *       'foreign_table' => 'tx_sometable_name',
     *       'foreign_field' => 'parent_uid',
     *       'foreign_match_fields' => [
     *           'parent_table' => 'pages',
     *       ],
     *  ],
     *
     * The child rows of an entry are the rows having all "matchFields" set to their values.
     * A child table can be child of multiple parent tables. The rows of different parent
     * tables are only told apart if their foreign_match_fields (or foreign_table_field)
     * have at least one field in common with different values. Entries that can not be
     * told apart from another parent table are skipped: It is unclear which parent table
     * their rows belong to.
     *
     * @return iterable<InlineForeignFieldNoForeignTableFieldParent>
     */
    public function getNextInlineForeignFieldNoForeignTableFieldParent(): iterable
    {
        $this->verifyTcaIsArray();
        // All inline parent fields with foreign_field, by child table
        $parentsByChildTable = [];
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $columnConfig) {
                if (!is_array($columnConfig['config'] ?? false)
                    || !in_array(($columnConfig['config']['type'] ?? ''), ['inline', 'file'], true)
                    || empty($columnConfig['config']['foreign_table'] ?? '')
                    || empty($columnConfig['config']['foreign_field'] ?? '')
                ) {
                    continue;
                }
                $matchFields = is_array($columnConfig['config']['foreign_match_fields'] ?? false) ? $columnConfig['config']['foreign_match_fields'] : [];
                $foreignTableField = $columnConfig['config']['foreign_table_field'] ?? '';
                if (is_string($foreignTableField) && $foreignTableField !== '') {
                    // The child rows of a parent with foreign_table_field have the parent table name in this field
                    $matchFields[$foreignTableField] = $tableName;
                }
                $parentsByChildTable[$columnConfig['config']['foreign_table']][] = [
                    'parentTableName' => $tableName,
                    'fieldNameOfParentTableUid' => $columnConfig['config']['foreign_field'],
                    'matchFields' => $matchFields,
                    'hasForeignTableField' => is_string($foreignTableField) && $foreignTableField !== '',
                ];
            }
        }
        $entries = [];
        foreach ($parentsByChildTable as $childTableName => $parents) {
            foreach ($parents as $parent) {
                if ($parent['hasForeignTableField']
                    || empty($GLOBALS['TCA'][$childTableName]['columns'][$parent['fieldNameOfParentTableUid']])
                ) {
                    continue;
                }
                foreach (array_keys($parent['matchFields']) as $matchFieldName) {
                    if (empty($GLOBALS['TCA'][$childTableName]['columns'][$matchFieldName])) {
                        // A match field without TCA column is not guaranteed to exist in the database.
                        continue 2;
                    }
                }
                foreach ($parents as $otherParent) {
                    if ($otherParent['parentTableName'] !== $parent['parentTableName']
                        && !$this->matchFieldsAreDisjoint($parent['matchFields'], $otherParent['matchFields'])
                    ) {
                        continue 2;
                    }
                }
                $entry = [
                    'tableName' => (string)$childTableName,
                    'parentTableName' => $parent['parentTableName'],
                    'fieldNameOfParentTableUid' => $parent['fieldNameOfParentTableUid'],
                    'matchFields' => $parent['matchFields'],
                ];
                $entries[serialize($entry)] = $entry;
            }
        }
        foreach ($entries as $entry) {
            yield new InlineForeignFieldNoForeignTableFieldParent(
                $entry['tableName'],
                $entry['parentTableName'],
                $entry['fieldNameOfParentTableUid'],
                $entry['matchFields'],
            );
        }
    }

    /**
     * True if no row can match both: There is a field in both sets having different values.
     *
     * @param array<string, int|string> $matchFields
     * @param array<string, int|string> $otherMatchFields
     */
    private function matchFieldsAreDisjoint(array $matchFields, array $otherMatchFields): bool
    {
        foreach ($matchFields as $fieldName => $value) {
            if (array_key_exists($fieldName, $otherMatchFields) && (string)$otherMatchFields[$fieldName] !== (string)$value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fields of type 'group' that store their relations as comma separated
     * list in the field itself, without MM table:
     *
     * 'config' => [
     *     'type' => 'group',
     *     'allowed' => 'tt_content',
     * ],
     *
     * Language pointer fields (TCA ctrl "transOrigPointerField" and "translationSource")
     * are skipped, they are handled by language related checks. Fields that are the
     * foreign_field or foreign_table_field of an inline relation are skipped as well:
     * They point to the inline parent, which may be a record of a table not listed in
     * "allowed". They are handled by inline related checks.
     *
     * @return iterable<GroupField>
     */
    public function getNextGroupFieldWithoutMm(): iterable
    {
        $this->verifyTcaIsArray();
        $inlineParentPointerFields = $this->getInlineParentPointerFields();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $fieldName => $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    && ($columnConfig['config']['type'] ?? '') === 'group'
                    && empty($columnConfig['config']['MM'])
                    && !empty($columnConfig['config']['allowed'])
                    && $fieldName !== $this->getTranslationParentField($tableName)
                    && $fieldName !== $this->getTranslationSourceField($tableName)
                    && !isset($inlineParentPointerFields[$tableName][$fieldName])
                ) {
                    $allowedTablesByType = $this->getAllowedTablesByType($tableName, $fieldName, $columnConfig);
                    if ($allowedTablesByType === null) {
                        continue;
                    }
                    yield new GroupField(
                        $tableName,
                        $fieldName,
                        GeneralUtility::trimExplode(',', $columnConfig['config']['allowed'], true),
                        $allowedTablesByType === [] ? null : $this->getTypeField($tableName),
                        $allowedTablesByType,
                    );
                }
            }
        }
    }

    /**
     * Fields of type 'select' with a foreign_table, and fields of type 'category',
     * that store their relations as comma separated list in the field itself,
     * without MM table:
     *
     * 'config' => [
     *     'type' => 'select',
     *     'foreign_table' => 'fe_groups',
     * ],
     *
     * The core sets foreign_table "sys_category" for all fields of type 'category'
     * in TcaPreparation, and an MM table for those with relationship "manyToMany".
     * Language pointer fields and fields pointing to an inline parent are skipped,
     * as in getNextGroupFieldWithoutMm().
     *
     * @return iterable<ForeignTableListField>
     */
    public function getNextSelectFieldWithoutMm(): iterable
    {
        $this->verifyTcaIsArray();
        $inlineParentPointerFields = $this->getInlineParentPointerFields();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $fieldName => $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    && in_array(($columnConfig['config']['type'] ?? ''), ['select', 'category'], true)
                    && empty($columnConfig['config']['MM'])
                    && is_string($columnConfig['config']['foreign_table'] ?? false)
                    && $columnConfig['config']['foreign_table'] !== ''
                    && $fieldName !== $this->getTranslationParentField($tableName)
                    && $fieldName !== $this->getTranslationSourceField($tableName)
                    && !isset($inlineParentPointerFields[$tableName][$fieldName])
                ) {
                    $allowedTablesByType = $this->getAllowedTablesByType($tableName, $fieldName, $columnConfig);
                    if ($allowedTablesByType === null) {
                        continue;
                    }
                    yield new ForeignTableListField(
                        $tableName,
                        $fieldName,
                        [$columnConfig['config']['foreign_table']],
                        $this->getStaticItemValues($tableName, $fieldName, $columnConfig),
                        $allowedTablesByType === [] ? null : $this->getTypeField($tableName),
                        $allowedTablesByType,
                    );
                }
            }
        }
    }

    /**
     * Fields of type 'inline' without foreign_field and without MM table: They store
     * the uids of their children as comma separated list in the field itself.
     *
     * 'config' => [
     *     'type' => 'inline',
     *     'foreign_table' => 'tx_ext_child',
     * ],
     *
     * @return iterable<ForeignTableListField>
     */
    public function getNextInlineFieldWithUidList(): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $fieldName => $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    && ($columnConfig['config']['type'] ?? '') === 'inline'
                    && empty($columnConfig['config']['MM'])
                    && empty($columnConfig['config']['foreign_field'])
                    && is_string($columnConfig['config']['foreign_table'] ?? false)
                    && $columnConfig['config']['foreign_table'] !== ''
                ) {
                    $allowedTablesByType = $this->getAllowedTablesByType($tableName, $fieldName, $columnConfig);
                    if ($allowedTablesByType === null) {
                        continue;
                    }
                    yield new ForeignTableListField(
                        $tableName,
                        $fieldName,
                        [$columnConfig['config']['foreign_table']],
                        [],
                        $allowedTablesByType === [] ? null : $this->getTypeField($tableName),
                        $allowedTablesByType,
                    );
                }
            }
        }
    }

    /**
     * Values of the static "items" of a select field, of the base config and of all record
     * types changing them with "columnsOverrides". A value may be a static item of one type
     * and a relation of another: Such values are never handled as relation.
     *
     * @param array<string, mixed> $columnConfig
     * @return array<int, string>
     */
    private function getStaticItemValues(string $tableName, string $fieldName, array $columnConfig): array
    {
        $itemLists = [$columnConfig['config']['items'] ?? null];
        foreach (($GLOBALS['TCA'][$tableName]['types'] ?? []) as $typeConfig) {
            $itemLists[] = $typeConfig['columnsOverrides'][$fieldName]['config']['items'] ?? null;
        }
        $values = [];
        foreach ($itemLists as $items) {
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item) && (is_int($item['value'] ?? null) || is_string($item['value'] ?? null))) {
                    $values[] = (string)$item['value'];
                }
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * Fields of inline children pointing to their parent, by child table: foreign_field
     * and foreign_table_field of inline and file fields. They point to the inline parent,
     * which may be a record of another table than their TCA says.
     *
     * @return array<string, array<string, true>>
     */
    private function getInlineParentPointerFields(): array
    {
        $inlineParentPointerFields = [];
        foreach ($GLOBALS['TCA'] as $config) {
            foreach (($config['columns'] ?? []) as $columnConfig) {
                if (is_array($columnConfig['config'] ?? false)
                    && in_array(($columnConfig['config']['type'] ?? ''), ['inline', 'file'], true)
                    && is_string($columnConfig['config']['foreign_table'] ?? false)
                ) {
                    foreach (['foreign_field', 'foreign_table_field'] as $pointerConfigName) {
                        if (is_string($columnConfig['config'][$pointerConfigName] ?? false)) {
                            $inlineParentPointerFields[$columnConfig['config']['foreign_table']][$columnConfig['config'][$pointerConfigName]] = true;
                        }
                    }
                }
            }
        }
        return $inlineParentPointerFields;
    }

    /**
     * Fields of type 'group' that store their relations in an MM table, and are
     * the "local" side of the relation: The MM rows have the uid of the record
     * in uid_local, and the related record in uid_foreign and tablenames.
     *
     * 'config' => [
     *     'type' => 'group',
     *     'allowed' => '*',
     *     'MM' => 'sys_category_record_mm',
     * ],
     *
     * The "foreign" side of a relation (MM_opposite_field set) is skipped: Its
     * relations are the MM rows of the local side. Fields with MM_table_where
     * are skipped as well: It is unclear which MM rows belong to them.
     *
     * @return iterable<MmField>
     */
    public function getNextGroupFieldWithMm(): iterable
    {
        return $this->getNextMmField('group');
    }

    /**
     * Fields of type 'select' with a foreign_table that store their relations in an
     * MM table, as local side, like getNextGroupFieldWithMm(). The core reads and writes
     * them as group fields with the foreign_table as single allowed table.
     *
     * 'config' => [
     *     'type' => 'select',
     *     'foreign_table' => 'tx_ext_tag',
     *     'MM' => 'tx_ext_item_tag_mm',
     * ],
     *
     * Fields of type 'category' are not included: The core sets MM_opposite_field for those
     * with MM table, their relations are the MM rows of sys_category field "items".
     *
     * @return iterable<MmField>
     */
    public function getNextSelectFieldWithMm(): iterable
    {
        return $this->getNextMmField('select');
    }

    /**
     * Fields of type 'inline' that store their child relations in an MM table, as
     * local side, like getNextGroupFieldWithMm(). The core reads and writes them as
     * select fields with MM.
     *
     * 'config' => [
     *     'type' => 'inline',
     *     'foreign_table' => 'tx_ext_child',
     *     'MM' => 'tx_ext_parent_child_mm',
     * ],
     *
     * Fields with MM table and foreign_field are skipped. This is broken TCA, but core
     * v13 and v14 do not reject it, and do not agree which of both is used:
     * - DataHandler->checkValue_inline_processDBdata() writes the children with foreign_field,
     *   it checks foreign_field before MM.
     * - RelationHandler->start() reads the children from the MM table, it checks MM before
     *   foreign_field. Backend and frontend do not see the children the DataHandler writes.
     * - TcaSchema RelationshipType::fromTcaConfiguration() returns ManyToMany for MM before
     *   looking at foreign_field.
     * - FormEngine TcaInline accepts either: $parentConfig['MM'] ?? $parentConfig['foreign_field'].
     * It is unclear which relations of such a field are valid.
     *
     * @return iterable<MmField>
     */
    public function getNextInlineFieldWithMm(): iterable
    {
        return $this->getNextMmField('inline');
    }

    /**
     * @return iterable<MmField>
     */
    private function getNextMmField(string $type): iterable
    {
        $this->verifyTcaIsArray();
        foreach ($GLOBALS['TCA'] as $tableName => $config) {
            foreach (($config['columns'] ?? []) as $fieldName => $columnConfig) {
                if (!is_array($columnConfig['config'] ?? false)
                    || ($columnConfig['config']['type'] ?? '') !== $type
                    || empty($columnConfig['config']['MM'])
                    || !is_string($columnConfig['config']['MM'])
                    || !empty($columnConfig['config']['MM_opposite_field'])
                    || !empty($columnConfig['config']['MM_table_where'])
                    || ($type === 'inline' && !empty($columnConfig['config']['foreign_field']))
                ) {
                    continue;
                }
                $allowedTables = $this->getRelationTablesOfConfig($columnConfig['config']);
                if ($allowedTables === []) {
                    continue;
                }
                $allowedTablesByType = $this->getAllowedTablesByType($tableName, $fieldName, $columnConfig);
                if ($allowedTablesByType === null) {
                    continue;
                }
                yield new MmField(
                    $tableName,
                    $fieldName,
                    $columnConfig['config']['MM'],
                    $allowedTables,
                    is_array($columnConfig['config']['MM_match_fields'] ?? false) ? $columnConfig['config']['MM_match_fields'] : [],
                    $allowedTablesByType === [] ? null : $this->getTypeField($tableName),
                    $allowedTablesByType,
                );
            }
        }
    }

    /**
     * Allowed tables of a relation field for a record of the given type, with a field from one of the
     * getNext*FieldWith*() methods. Null if the relations
     * of the record can not be resolved unambiguously, and must be left alone.
     *
     * A type value that is not a key of TCA "types", for instance a CType of a removed
     * extension, is resolved differently by the core: FormEngine and core v13 DataHandler
     * fall back to type "0", or "1" if "0" does not exist, and use the field config of that
     * type. Core v14 DataHandler (BackendUtility::getTCAtypeValue() with $skipFallback) and
     * the Record API use the base config of the field. If the fallback type changes "allowed",
     * the relations are ambiguous.
     *
     * @return array<int, string>|null
     */
    public function getAllowedTablesOfRecordType(GroupField|MmField|ForeignTableListField $groupField, ?string $recordType): ?array
    {
        if ($groupField->typeField === null) {
            return $groupField->allowedTables;
        }
        if ($recordType !== null && isset($GLOBALS['TCA'][$groupField->tableName]['types'][$recordType])) {
            return array_key_exists($recordType, $groupField->allowedTablesByType)
                ? $groupField->allowedTablesByType[$recordType]
                : $groupField->allowedTables;
        }
        $fallbackType = isset($GLOBALS['TCA'][$groupField->tableName]['types']['0']) ? '0' : '1';
        if (array_key_exists($fallbackType, $groupField->allowedTablesByType)) {
            return null;
        }
        return $groupField->allowedTables;
    }

    /**
     * True if a relation field may point to a record of a workspace aware table, with the
     * allowed tables of all record types. "*" allows any table.
     */
    public function hasWorkspaceAwareTargetTable(GroupField|MmField|ForeignTableListField $field): bool
    {
        $tableNames = $field->allowedTables;
        foreach ($field->allowedTablesByType as $allowedTablesOfType) {
            $tableNames = array_merge($tableNames, $allowedTablesOfType ?? []);
        }
        foreach ($tableNames as $tableName) {
            if ($tableName === '*' || $this->getWorkspaceIdField($tableName) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record types can change the config of a field with TCA "columnsOverrides". This returns
     * the allowed tables of a relation field per type, for types that change them: TCA "allowed"
     * of group fields, "foreign_table" of select, category and inline fields. Empty array if
     * no type changes them, the normal case. A type maps to null if its records of this field
     * must be left alone. Null if the whole field must be left alone.
     *
     * dbdoctor follows the core DataHandler when it writes a record (checkValue()): It decides
     * which relations end up in the database. The DataHandler uses the field config of the record
     * type, with "columnsOverrides" merged into the base config of "columns", but only if the
     * field is shown by the type: It must be listed in "showitem" of the type, directly or in a
     * palette. Otherwise the base config is used, even if "columnsOverrides" changes it. This is
     * the "sub schema" of core TcaSchema, rebuilt here on the TCA array since dbdoctor does not use
     * TcaSchema, see README.md. A list item without table prefix, like "42", is a record of the
     * first allowed table. The DataHandler writes items without prefix if there is only one
     * allowed table, and MM rows without "tablenames".
     *
     * Core and its documentation do not agree on this: The TCA reference documentation states
     * "columnsOverrides" is for display options only, is ignored by the DataHandler, and must not
     * change "type". The core does not prevent any of it. Overriding "allowed" works in the backend,
     * but the core itself is not consistent:
     * - Core v13 DataHandler uses "columnsOverrides" in checkValue() only, when a record is
     *   written. Copy, localize and delete use the base config.
     * - Core v14 DataHandler uses it in checkValue(), copy, localize, move and delete. Remapping
     *   relations after copy or localize, undelete, and discarding or publishing MM relations in
     *   workspaces use the base config.
     * - The reference index and the relation map of TcaSchema use the base config only: Items of
     *   such fields end up as relations to the wrong table in sys_refindex.
     * - FormEngine merges "columnsOverrides" of the type even for fields not in "showitem",
     *   those are not rendered.
     * - The database schema of MM tables is derived from the base config: The columns "tablenames"
     *   and "fieldname" are only added for fields allowing multiple tables. A type allowing multiple
     *   tables for a field with a single allowed table makes the DataHandler write "tablenames"
     *   into an MM table that may not have this column.
     * - Type values without "types" entry are resolved differently, see getAllowedTablesOfRecordType().
     *
     * Rules of dbdoctor:
     * - Only "allowed" or "foreign_table" may differ per type. The relations of a type that changes
     *   the field "type" ("columnsOverrides" turning a group field into something else) or adds an
     *   MM table to a field without, are left alone.
     * - A type that changes the MM settings of a field with MM table (MM, MM_match_fields,
     *   MM_opposite_field, MM_table_where) makes the whole field left alone: Its MM rows can not
     *   be assigned to a type, and the core reads and writes them with different configs itself.
     * - If the type of a record is defined in another table (TCA ctrl "type" like "uid_local:type",
     *   as in sys_file_reference), resolving it would need a query per record: Such a field is
     *   left alone if a type changes its tables, and handled with the base config otherwise.
     *
     * @param array<string, mixed> $columnConfig
     * @return array<array-key, array<int, string>|null>|null
     */
    private function getAllowedTablesByType(string $tableName, string $fieldName, array $columnConfig): ?array
    {
        $typeConfiguration = $GLOBALS['TCA'][$tableName]['ctrl']['type'] ?? '';
        if (!is_string($typeConfiguration) || $typeConfiguration === '') {
            // No record types: The core uses the base config.
            return [];
        }
        $baseConfig = $columnConfig['config'];
        $baseAllowedTables = $this->getRelationTablesOfConfig($baseConfig);
        $hasMm = !empty($baseConfig['MM']);
        $allowedTablesByType = [];
        foreach (($GLOBALS['TCA'][$tableName]['types'] ?? []) as $recordType => $typeConfig) {
            $recordType = (string)$recordType;
            if (!is_array($typeConfig['columnsOverrides'][$fieldName] ?? null)
                || !$this->isFieldShownInType($tableName, $recordType, $fieldName)
            ) {
                continue;
            }
            $config = array_replace_recursive($columnConfig, $typeConfig['columnsOverrides'][$fieldName])['config'] ?? null;
            if (!is_array($config)) {
                $allowedTablesByType[$recordType] = null;
                continue;
            }
            if ($hasMm) {
                foreach (['MM', 'MM_match_fields', 'MM_opposite_field', 'MM_table_where'] as $mmConfigName) {
                    if (($config[$mmConfigName] ?? null) !== ($baseConfig[$mmConfigName] ?? null)) {
                        return null;
                    }
                }
            }
            if (($config['type'] ?? '') !== $baseConfig['type'] || (!$hasMm && !empty($config['MM']))) {
                $allowedTablesByType[$recordType] = null;
                continue;
            }
            $allowedTables = $this->getRelationTablesOfConfig($config);
            if ($allowedTables === $baseAllowedTables) {
                continue;
            }
            $allowedTablesByType[$recordType] = $allowedTables === [] ? null : $allowedTables;
        }
        if ($allowedTablesByType !== [] && str_contains($typeConfiguration, ':')) {
            return null;
        }
        return $allowedTablesByType;
    }

    /**
     * Tables a group field ("allowed") or a select, category or inline field ("foreign_table") relates to.
     *
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function getRelationTablesOfConfig(array $config): array
    {
        $tables = ($config['type'] ?? '') === 'group' ? ($config['allowed'] ?? null) : ($config['foreign_table'] ?? null);
        return is_string($tables) ? GeneralUtility::trimExplode(',', $tables, true) : [];
    }

    /**
     * True if a field is shown by a record type: Listed in its "showitem", directly or
     * in a palette referenced there. As core TcaSchemaBuilder->findRelevantFieldsForSubSchema().
     */
    private function isFieldShownInType(string $tableName, string $recordType, string $fieldName): bool
    {
        $showItem = $GLOBALS['TCA'][$tableName]['types'][$recordType]['showitem'] ?? '';
        foreach (GeneralUtility::trimExplode(',', is_string($showItem) ? $showItem : '', true) as $showItemEntry) {
            [$entryName, , $paletteName] = GeneralUtility::trimExplode(';', $showItemEntry . ';;;');
            if ($entryName === $fieldName) {
                return true;
            }
            if ($entryName !== '--palette--' || $paletteName === '') {
                continue;
            }
            $paletteShowItem = $GLOBALS['TCA'][$tableName]['palettes'][$paletteName]['showitem'] ?? '';
            foreach (GeneralUtility::trimExplode(',', is_string($paletteShowItem) ? $paletteShowItem : '', true) as $paletteEntry) {
                if (GeneralUtility::trimExplode(';', $paletteEntry . ';')[0] === $fieldName) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * All inline and file fields of all tables having the given table as children: The full
     * relation map of a child table, also of child tables having multiple parent tables.
     *
     * @return list<array{tableName: string, fieldName: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, int|string>}>
     */
    public function getInlineParentsOfTable(string $childTableName): array
    {
        $this->verifyTcaIsArray();
        $parents = [];
        foreach ($GLOBALS['TCA'] as $tableName => $tableConfig) {
            foreach (($tableConfig['columns'] ?? []) as $fieldName => $columnConfig) {
                $config = $columnConfig['config'] ?? null;
                if (!is_array($config)
                    || !in_array($config['type'] ?? '', ['inline', 'file'], true)
                    || ($config['foreign_table'] ?? '') !== $childTableName
                    || empty($config['foreign_field'])
                ) {
                    continue;
                }
                $parents[] = [
                    'tableName' => (string)$tableName,
                    'fieldName' => (string)$fieldName,
                    'foreignField' => (string)$config['foreign_field'],
                    'foreignTableField' => empty($config['foreign_table_field']) ? null : (string)$config['foreign_table_field'],
                    'foreignMatchFields' => is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [],
                ];
            }
        }
        return $parents;
    }

    public function getDeletedField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['delete'] ?? null) ?: null;
    }

    public function getHiddenField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled'] ?? null) ?: null;
    }

    /**
     * Fields of TCA enablecolumns "disabled", "starttime" and "endtime" the table has.
     *
     * @return array<int, string>
     */
    public function getVisibilityFields(string $tableName): array
    {
        $fields = [];
        foreach (['disabled', 'starttime', 'endtime'] as $enableColumn) {
            $field = $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns'][$enableColumn] ?? '';
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * If the frontend shows a record regarding hidden, start time and end time, as
     * FrontendRestrictionContainer does. Frontend user groups are not considered.
     * The row must contain the fields of getVisibilityFields().
     *
     * @param array<string, int|string|null> $row
     */
    public function isVisibleInFrontend(string $tableName, array $row, int $now): bool
    {
        $enableColumns = $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns'] ?? [];
        $hiddenField = (string)($enableColumns['disabled'] ?? '');
        $startTimeField = (string)($enableColumns['starttime'] ?? '');
        $endTimeField = (string)($enableColumns['endtime'] ?? '');
        return ($hiddenField === '' || (int)$row[$hiddenField] === 0)
            && ($startTimeField === '' || (int)$row[$startTimeField] <= $now)
            && ($endTimeField === '' || (int)$row[$endTimeField] === 0 || (int)$row[$endTimeField] > $now);
    }

    public function getTimestampField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['tstamp'] ?? null) ?: null;
    }

    public function getLanguageField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['languageField'] ?? null) ?: null;
    }

    public function getTranslationParentField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? null) ?: null;
    }

    public function getTranslationSourceField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['translationSource'] ?? null) ?: null;
    }

    /**
     * @return non-empty-string|null
     */
    public function getWorkspaceIdField(string $tableName): ?string
    {
        return ($GLOBALS['TCA'][$tableName]['ctrl']['versioningWS'] ?? null) ? 't3ver_wsid' : null;
    }

    public function getTypeField(string $tableName): ?string
    {
        if (empty($GLOBALS['TCA'][$tableName]['ctrl']['type'])
            || str_contains($GLOBALS['TCA'][$tableName]['ctrl']['type'], ':')
        ) {
            return null;
        }
        return $GLOBALS['TCA'][$tableName]['ctrl']['type'];
    }

    /**
     * @return string[]|null
     */
    public function getLabelFields(string $tableName): ?array
    {
        if (!isset($GLOBALS['TCA'][$tableName]['ctrl']['label']) && !isset($GLOBALS['TCA'][$tableName]['ctrl']['label_alt'])) {
            return null;
        }

        $result = [];
        if ($GLOBALS['TCA'][$tableName]['ctrl']['label'] ?? false) {
            $result[] = $GLOBALS['TCA'][$tableName]['ctrl']['label'];
        }
        if ($GLOBALS['TCA'][$tableName]['ctrl']['label_alt'] ?? false) {
            $result = array_merge($result, GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$tableName]['ctrl']['label_alt'], true));
        }
        return $result;
    }

    private function verifyTcaIsArray(): void
    {
        if (!is_array($GLOBALS['TCA'])) {
            throw new \RuntimeException('TCA is not an array!', 1688203176);
        }
    }
}
