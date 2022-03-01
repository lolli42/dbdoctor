<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Renderer;

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

use Lolli\Dbhealth\Exception\NoSuchRecordException;
use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Helper\TcaHelper;

class RecordsRenderer
{
    /**
     * @var array<int, string>
     */
    private array $crUserCache = [];
    /**
     * @var array<int, string>
     */
    private array $workspaceCache = [];

    private RecordsHelper $recordsHelper;
    private TcaHelper $tcaHelper;

    public function __construct(
        RecordsHelper $recordsHelper,
        TcaHelper $tcaHelper
    ) {
        $this->recordsHelper = $recordsHelper;
        $this->tcaHelper = $tcaHelper;
    }

    /**
     * @return string[]
     */
    public function getHeader(string $tableName): array
    {
        return $this->getRelevantFieldNames($tableName);
    }

    /**
     * @param array<int, int|string> $recordUids
     * @return array<int, array<string, int|string>>
     */
    public function getRows(string $tableName, array $recordUids): array
    {
        $fields = $this->getRelevantFieldNames($tableName);
        $rows = [];
        foreach ($recordUids as $uid) {
            $row = $this->recordsHelper->getRecord($tableName, $fields, (int)$uid);
            $row = $this->humanReadableTimestamp($tableName, $row);
            $row = $this->resolveCrUser($tableName, $row);
            $row = $this->resolveWorkspace($tableName, $row);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @return string[]
     */
    private function getRelevantFieldNames(string $tableName): array
    {
        $fields = [
            'uid',
            'pid',
        ];
        if ($field = $this->tcaHelper->getDeletedField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getCreateUserIdField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getTimestampField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getLanguageField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getWorkspaceIdField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getTypeField($tableName)) {
            $fields[] = $field;
        }
        if ($field = $this->tcaHelper->getLabelFields($tableName)) {
            $fields = array_merge($fields, $field);
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
    private function resolveCrUser(string $tableName, array $row): array
    {
        $crUserField = $this->tcaHelper->getCreateUserIdField($tableName);
        if ($crUserField) {
            $crUserUid = (int)$row[$crUserField];
            if ($crUserUid > 0) {
                if (!($this->crUserCache[$crUserUid] ?? false)) {
                    try {
                        $user = $this->recordsHelper->getRecord('be_users', ['username', 'deleted'], $crUserUid);
                        $deletedString = $user['deleted'] ? '|<info>deleted</info>' : '';
                        $crUserString = '[' . $crUserUid . $deletedString . ']' . $user['username'];
                    } catch (NoSuchRecordException $e) {
                        $crUserString = '[' . $crUserUid . '|<comment>missing</comment>]';
                    }
                    $this->crUserCache[$crUserUid] = $crUserString;
                }
                $row[$crUserField] = $this->crUserCache[$crUserUid];
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
    private function resolveWorkspace(string $tableName, array $row): array
    {
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        if ($workspaceIdField) {
            $workspaceUid = (int)$row[$workspaceIdField];
            if ($workspaceUid > 0) {
                if (!($this->workspaceCache[$workspaceUid] ?? false)) {
                    try {
                        $workspace = $this->recordsHelper->getRecord('sys_workspace', ['title', 'deleted'], $workspaceUid);
                        $deletedString = $workspace['deleted'] ? '|<info>deleted</info>' : '';
                        $workspaceString = '[' . $workspaceUid . $deletedString . ']' . $workspace['title'];
                    } catch (NoSuchRecordException $e) {
                        $workspaceString = '[' . $workspaceUid . '|<comment>missing</comment>]';
                    }
                    $this->workspaceCache[$workspaceUid] = $workspaceString;
                }
                $row[$workspaceIdField] = $this->workspaceCache[$workspaceUid];
            } else {
                $row[$workspaceIdField] = '[0]';
            }
        }
        return $row;
    }
}
