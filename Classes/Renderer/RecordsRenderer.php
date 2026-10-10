<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Renderer;

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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;

final readonly class RecordsRenderer
{
    public function __construct(
        private RecordsHelper $recordsHelper,
        private TcaHelper $tcaHelper,
        private TableHelper $tableHelper,
    ) {}

    /**
     * @param array<int, string> $extraCtrlFields
     * @param array<int, string> $extraDbFields
     * @return string[]
     */
    public function getHeader(
        string $tableName,
        string $reasonField = '',
        array $extraCtrlFields = [],
        array $extraDbFields = []
    ): array {
        $fields = $this->getRelevantFieldNames($tableName, $extraCtrlFields, $extraDbFields);
        if ($reasonField) {
            $fields = array_merge(['reason'], $fields);
        }
        return $fields;
    }

    /**
     * @param array<int, array<string, int|string>> $incomingRows
     * @param array<int, string> $extraCtrlFields
     * @param array<int, string> $extraDbFields
     * @return array<int, array<string, int|string>>
     */
    public function getRows(
        PreparedStatements $statements,
        string $tableName,
        array $incomingRows,
        string $reasonField = '',
        array $extraCtrlFields = [],
        array $extraDbFields = []
    ): array {
        $fields = $this->getRelevantFieldNames($tableName, $extraCtrlFields, $extraDbFields);
        $rows = [];
        foreach ($incomingRows as $incomingRow) {
            $row = $this->recordsHelper->getRecord($statements, $tableName, $fields, (int)$incomingRow['uid']);
            if ($reasonField) {
                $reason = ['reason' => $incomingRow['_reasonBroken']];
                $row = array_merge($reason, $row);
            }
            if ($tableName === 'sys_file_reference'
                && isset($row['uid_local'])
                && isset($row['uid_foreign']) && isset($row['tablenames'])
            ) {
                // Maybe make this more generic, we 'll see.
                $row['uid_local'] = $this->resolveRelation($statements, 'sys_file', (int)($row['uid_local']));
                $row['tablenames'] = $this->resolveRelationTable((string)$row['tablenames']);
                if ($this->tableHelper->tableExistsInDatabase((string)$row['tablenames'])) {
                    $row['uid_foreign'] = $this->resolveRelation($statements, (string)$row['tablenames'], (int)($row['uid_foreign']));
                }
            }
            $row = $this->humanReadableTimestamp($tableName, $row);
            $row = $this->resolveCrUser($statements, $tableName, $row);
            $row = $this->resolveWorkspace($statements, $tableName, $row);
            $row = $this->resolvePid($statements, $row);
            $row = $this->resolveTranslationParentField($statements, $tableName, $row);
            $row = $this->resolveTranslationSourceField($statements, $tableName, $row);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<int, string> $extraCtrlFields
     * @param array<int, string> $extraDbFields
     * @return string[]
     */
    private function getRelevantFieldNames(string $tableName, array $extraCtrlFields, array $extraDbFields): array
    {
        $fields = $this->addField([], 'uid');
        $fields = $this->addField($fields, 'pid');
        if (!empty($extraCtrlFields)) {
            foreach ($extraCtrlFields as $extraField) {
                $fields = $this->addField($fields, $this->tcaHelper->getFieldNameByCtrlName($tableName, $extraField));
            }
        }
        $fields = $this->addFields($fields, $extraDbFields);
        $fields = $this->addField($fields, $this->tcaHelper->getDeletedField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getCreateUserIdField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getTimestampField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getLanguageField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getTranslationParentField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getTranslationSourceField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getWorkspaceIdField($tableName));
        $fields = $this->addField($fields, $this->tcaHelper->getTypeField($tableName));
        return $this->addFields($fields, $this->tcaHelper->getLabelFields($tableName));
    }

    /**
     * @param array<int, string> $fields
     * @param array<int, string> $additions
     * @return string[]
     */
    private function addFields(array $fields, ?array $additions): array
    {
        if (!empty($additions)) {
            foreach ($additions as $labelField) {
                $fields = $this->addField($fields, $labelField);
            }
        }
        return $fields;
    }

    /**
     * @param array<int, string> $fields
     * @return string[]
     */
    private function addField(array $fields, ?string $field): array
    {
        if ($field && !in_array($field, $fields, true)) {
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function humanReadableTimestamp(string $tableName, array $row): array
    {
        $timestampField = $this->tcaHelper->getTimestampField($tableName);
        if ($timestampField) {
            $timestampValue = $row[$timestampField];
            if ($timestampValue > 0) {
                $date = new \DateTime('@' . $timestampValue);
                $row[$timestampField] = $date->format('Y-m-d H:i') . ' UTC';
            }
        }
        return $row;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function resolveCrUser(PreparedStatements $statements, string $tableName, array $row): array
    {
        $crUserField = $this->tcaHelper->getCreateUserIdField($tableName);
        if ($crUserField) {
            $crUserUid = (int)$row[$crUserField];
            if ($crUserUid > 0) {
                try {
                    // Not checking TCA ctrl for be_users soft-delete-awareness here:
                    // Hopefully nobody unsets this, and it is likely core would stumble on this, too.
                    $user = $this->recordsHelper->getRecord($statements, 'be_users', ['username', 'deleted'], $crUserUid);
                    $deletedString = $user['deleted'] ? '|<info>deleted</info>' : '';
                    $row[$crUserField] = '[' . $crUserUid . $deletedString . ']' . $user['username'];
                } catch (NoSuchRecordException) {
                    $row[$crUserField] = '[' . $crUserUid . '|<comment>missing</comment>]';
                }
            } else {
                $row[$crUserField] = '[<comment>0</comment>]';
            }
        }
        return $row;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function resolveWorkspace(PreparedStatements $statements, string $tableName, array $row): array
    {
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        if ($workspaceIdField) {
            $workspaceUid = (int)$row[$workspaceIdField];
            if ($workspaceUid > 0) {
                try {
                    // Not checking TCA ctrl for sys_workspace soft-delete-awareness here:
                    // Hopefully nobody unsets this, and it is likely core would stumble on this, too.
                    $workspace = $this->recordsHelper->getRecord($statements, 'sys_workspace', ['title', 'deleted'], $workspaceUid);
                    $deletedString = $workspace['deleted'] ? '|<info>deleted</info>' : '';
                    $row[$workspaceIdField] = '[' . $workspaceUid . $deletedString . ']' . $workspace['title'];
                } catch (NoSuchRecordException) {
                    $row[$workspaceIdField] = '[' . $workspaceUid . '|<comment>missing</comment>]';
                } catch (NoSuchTableException) {
                    $row[$workspaceIdField] = '[' . $workspaceUid . '|<comment>no sys_workspace table</comment>]';
                }
            } else {
                $row[$workspaceIdField] = '[0]Live';
            }
        }
        return $row;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function resolveTranslationParentField(PreparedStatements $statements, string $tableName, array $row): array
    {
        $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
        if ($translationParentField && array_key_exists($translationParentField, $row)) {
            $parentUid = (int)$row[$translationParentField];
            if ($parentUid === 0) {
                $row[$translationParentField] = '0';
            } else {
                $deletedField = $this->tcaHelper->getDeletedField($tableName);
                if ($deletedField === null) {
                    $row[$translationParentField] = '[' . $parentUid . '|<comment>missing</comment>]';
                } else {
                    try {
                        $parentRecord = $this->recordsHelper->getRecord($statements, $tableName, [$deletedField], $parentUid);
                        if ($parentRecord[$deletedField]) {
                            $row[$translationParentField] = '[' . $parentUid . '|<info>deleted</info>]';
                        }
                    } catch (NoSuchRecordException) {
                        $row[$translationParentField] = '[' . $parentUid . '|<comment>missing</comment>]';
                    }
                }
            }
        }
        return $row;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function resolveTranslationSourceField(PreparedStatements $statements, string $tableName, array $row): array
    {
        $translationSourceField = $this->tcaHelper->getTranslationSourceField($tableName);
        if ($translationSourceField && array_key_exists($translationSourceField, $row)) {
            $parentUid = (int)$row[$translationSourceField];
            if ($parentUid === 0) {
                $row[$translationSourceField] = '0';
            } else {
                $deletedField = $this->tcaHelper->getDeletedField($tableName);
                if ($deletedField === null) {
                    $row[$translationSourceField] = '[' . $parentUid . '|<comment>missing</comment>]';
                } else {
                    try {
                        $parentRecord = $this->recordsHelper->getRecord($statements, $tableName, [$deletedField], $parentUid);
                        if ($parentRecord[$deletedField]) {
                            $row[$translationSourceField] = '[' . $parentUid . '|<info>deleted</info>]';
                        }
                    } catch (NoSuchRecordException) {
                        $row[$translationSourceField] = '[' . $parentUid . '|<comment>missing</comment>]';
                    }
                }
            }
        }
        return $row;
    }

    /**
     * @param array<string, int|string> $row
     * @return array<string, int|string>
     */
    private function resolvePid(PreparedStatements $statements, array $row): array
    {
        if (array_key_exists('pid', $row) && (int)$row['pid'] !== 0
        ) {
            $pagesUid = (int)$row['pid'];
            try {
                // Not checking TCA ctrl for pages soft-delete-awareness here:
                // Hopefully nobody unsets this, and it is likely core would stumble on this, too.
                $pagesRecord = $this->recordsHelper->getRecord($statements, 'pages', ['uid', 'deleted'], $pagesUid);
                if ($pagesRecord['deleted']) {
                    $row['pid'] = '[' . $pagesUid . '|<info>deleted</info>]';
                }
            } catch (NoSuchRecordException) {
                $row['pid'] = '[' . $pagesUid . '|<comment>missing</comment>]';
            }
        }
        return $row;
    }

    private function resolveRelation(PreparedStatements $statements, string $tableName, int $uid): string
    {
        try {
            $this->recordsHelper->getRecord($statements, $tableName, ['uid'], $uid);
        } catch (NoSuchRecordException) {
            return '[<comment>missing</comment>]' . $uid;
        }
        return (string)$uid;
    }

    private function resolveRelationTable(string $tableName): string
    {
        if (empty($tableName)) {
            return '[<comment>empty</comment>]' . $tableName;
        }
        if (!$this->tableHelper->tableExistsInDatabase($tableName)) {
            return '[<comment>missing</comment>]' . $tableName;
        }
        return $tableName;
    }
}
