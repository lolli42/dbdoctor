<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\StructuredOutput;

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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads records for structured output: TCA ctrl fields with role names, the same for
 * every table, raw values. Labels only with tier "labels", never of tables with personal data.
 */
final readonly class RecordReader
{
    /**
     * Tables whose labels are personal data: Never shown, regardless of the tier.
     */
    private const TABLES_WITHOUT_LABELS = [
        'be_users',
        'be_groups',
        'fe_users',
        'fe_groups',
        'sys_log',
        'sys_history',
    ];

    public function __construct(
        private TcaHelper $tcaHelper,
        private RecordsHelper $recordsHelper,
        private TableHelper $tableHelper,
        private ConnectionPool $connectionPool,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
    ) {}

    /**
     * Role name => field name of a table, only roles the table has.
     *
     * @return array<string, string>
     */
    public function getRoles(string $tableName): array
    {
        $ctrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
        $roles = [
            'deleted' => $this->tcaHelper->getDeletedField($tableName),
            'hidden' => $this->tcaHelper->getHiddenField($tableName),
            'starttime' => ($ctrl['enablecolumns']['starttime'] ?? null) ?: null,
            'endtime' => ($ctrl['enablecolumns']['endtime'] ?? null) ?: null,
            'language' => $this->tcaHelper->getLanguageField($tableName),
            'l10nParent' => $this->tcaHelper->getTranslationParentField($tableName),
            'l10nSource' => $this->tcaHelper->getTranslationSourceField($tableName),
            'type' => $this->tcaHelper->getTypeField($tableName),
            'tstamp' => $this->tcaHelper->getTimestampField($tableName),
            'crdate' => ($ctrl['crdate'] ?? null) ?: null,
            'cruserId' => $this->tcaHelper->getCreateUserIdField($tableName),
        ];
        if ($this->tcaHelper->getWorkspaceIdField($tableName) !== null) {
            $roles['workspaceId'] = 't3ver_wsid';
            $roles['workspaceState'] = 't3ver_state';
            $roles['workspaceOid'] = 't3ver_oid';
        }
        return array_filter($roles, static fn(?string $field): bool => $field !== null);
    }

    /**
     * @return list<string>
     */
    public function getLabelFields(string $tableName, string $tier): array
    {
        if ($tier !== ReportOptions::TIER_LABELS || in_array($tableName, self::TABLES_WITHOUT_LABELS, true)) {
            return [];
        }
        return array_values($this->tcaHelper->getLabelFields($tableName) ?? []);
    }

    /**
     * A record with uid, pid and its roles, a "workspace" object for workspace aware tables,
     * "labels" with tier "labels", and the raw values of the additionally requested fields
     * in "fields". Null if the record does not exist.
     *
     * @param list<string> $fields Database fields needed additionally, for instance written ones
     * @return array<string, mixed>|null
     */
    public function read(PreparedStatements $statements, string $tableName, int $uid, array $fields, string $tier): ?array
    {
        $roles = $this->getRoles($tableName);
        $labelFields = $this->getLabelFields($tableName, $tier);
        $selectFields = array_values(array_unique(array_merge(['uid', 'pid'], array_values($roles), $labelFields, $fields)));
        try {
            $row = $this->recordsHelper->getRecord($statements, $tableName, $selectFields, $uid);
        } catch (NoSuchRecordException) {
            return null;
        }
        $record = [
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
        ];
        foreach ($roles as $role => $field) {
            if (str_starts_with($role, 'workspace')) {
                continue;
            }
            $record[$role] = $this->castValue($tableName, $field, $row[$field]);
        }
        if (isset($roles['workspaceId'])) {
            $record['workspace'] = [
                'id' => (int)$row['t3ver_wsid'],
                'state' => (int)$row['t3ver_state'],
                'oid' => (int)$row['t3ver_oid'],
            ];
        }
        if ($labelFields !== []) {
            $record['labels'] = array_combine($labelFields, array_map(fn(string $field): int|string|null => $this->castValue($tableName, $field, $row[$field]), $labelFields));
        }
        if ($fields !== []) {
            $record['fields'] = array_combine($fields, array_map(fn(string $field): int|string|null => $this->castValue($tableName, $field, $row[$field]), $fields));
        }
        return $record;
    }

    /**
     * Number of rows of a table, including soft-deleted and workspace records.
     */
    public function countRows(string $tableName): int
    {
        $cacheIdentifier = 'dbdoctor-row-count-' . $tableName;
        if ($this->runtimeCache->has($cacheIdentifier)) {
            return (int)$this->runtimeCache->get($cacheIdentifier);
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $count = (int)$queryBuilder->count('*')->from($tableName)->executeQuery()->fetchOne();
        $this->runtimeCache->set($cacheIdentifier, $count);
        return $count;
    }

    /**
     * Values of integer columns as integer, they come as strings from some drivers.
     */
    private function castValue(string $tableName, string $fieldName, mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }
        return $this->tableHelper->fieldIsInteger($tableName, $fieldName) ? (int)$value : (string)$value;
    }
}
