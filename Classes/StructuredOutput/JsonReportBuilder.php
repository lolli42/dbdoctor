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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\MmKey;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInspection;
use Lolli\Dbdoctor\HealthCheck\InspectionStatus;
use Lolli\Dbdoctor\Helper\PagesRootlineHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Builds the structured report of a dbdoctor run as array, for JSON output. Experimental:
 * The structure may change with any release while the schema version is 0.x. Check classes
 * and their order are no API in any case.
 */
final readonly class JsonReportBuilder
{
    public const SCHEMA_VERSION = '0.4';

    // A table is suspicious if a check flags more than this share of its rows, and more than this number of rows
    private const SUSPICIOUS_SHARE = 0.5;
    private const SUSPICIOUS_MIN_ROWS = 100;
    // Finding ids per group as samples
    private const GROUP_SAMPLES = 3;

    public function __construct(
        private RecordReader $recordReader,
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
        private PagesRootlineHelper $pagesRootlineHelper,
        private TcaHelper $tcaHelper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildRun(ReportOptions $options, int $durationMs, int $exitCode): array
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        // The extension version is maintained in composer.json, in composer and classic mode
        $composerManifest = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);
        $dbdoctorVersion = $composerManifest['extra']['typo3/cms']['version'] ?? null;
        $optionsData = [
            'scope' => $options->scope->value,
            'tier' => $options->tier->value,
            'limit' => $options->limit ?? 'all',
            'context' => $options->context->value,
            'filter' => (object)array_filter([
                'table' => $options->filterTable,
                'uid' => $options->filterUid,
                'pid' => $options->filterPid,
                'subtree' => $options->filterSubtree,
                'check' => $options->filterChecks === [] ? null : $options->filterChecks,
            ], static fn(int|string|array|null $value): bool => $value !== null),
        ];
        if ($options->tier === ReportTier::Labels) {
            $optionsData['labels'] = ['tagsStripped' => true, 'maxLength' => RecordReader::LABEL_MAX_LENGTH];
        }
        return [
            'execTime' => (int)$GLOBALS['EXEC_TIME'],
            'dbdoctor' => $dbdoctorVersion,
            'typo3' => (new Typo3Version())->getVersion(),
            'php' => PHP_VERSION,
            'database' => [
                'platform' => (new \ReflectionClass($connection->getDatabasePlatform()))->getShortName(),
                'serverVersion' => $connection->getServerVersion(),
                'driver' => (string)($connection->getParams()['driver'] ?? ''),
            ],
            'workspacesLoaded' => ExtensionManagementUtility::isLoaded('workspaces'),
            'options' => $optionsData,
            'durationMs' => $durationMs,
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Root pages and languages of all sites with their fallbacks, identifiers and titles only
     * with tier "labels".
     *
     * @return list<array<string, mixed>>
     */
    public function buildSites(ReportOptions $options): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            $languages = [];
            foreach ($site->getAllLanguages() as $language) {
                $languageData = ['id' => $language->getLanguageId()];
                if ($options->tier === ReportTier::Labels) {
                    $languageData['title'] = $language->getTitle();
                }
                if ($language->getLanguageId() > 0) {
                    // Whether a page without translation in this language is shown, and with which content
                    $languageData['fallbackType'] = $language->getFallbackType();
                    $languageData['fallbacks'] = $language->getFallbackLanguageIds();
                }
                $languages[] = $languageData;
            }
            $siteData = ['rootPageId' => $site->getRootPageId(), 'languages' => $languages];
            if ($options->tier === ReportTier::Labels) {
                $siteData = ['identifier' => $site->getIdentifier()] + $siteData;
            }
            $sites[] = $siteData;
        }
        return $sites;
    }

    /**
     * A check that was not run, or that does not support structured output.
     *
     * @param class-string $className
     * @return array<string, mixed>
     */
    public function buildCheckWithoutInspection(int $position, string $className, string $status): array
    {
        return [
            'position' => $position,
            'class' => (new \ReflectionClass($className))->getShortName(),
            'className' => $className,
            'status' => $status,
        ];
    }

    /**
     * @param class-string $className
     * @param \Closure(array<string, mixed>): void|null $findingWriter Receives the findings one by one instead of the check
     * @return array{check: array<string, mixed>, tables: list<string>, pages: array<int, array<string, mixed>>}
     */
    public function buildCheck(int $position, string $className, HealthCheckInspection $inspection, bool $preliminary, ReportOptions $options, ?\Closure $findingWriter = null): array
    {
        if ($inspection->status !== InspectionStatus::Findings) {
            // Ok and skipped checks have no findings to judge, their description is in the check
            $check = [
                'position' => $position,
                'class' => (new \ReflectionClass($className))->getShortName(),
                'status' => $inspection->status->value,
                'preliminary' => $preliminary,
            ];
            if ($inspection->skipReason !== null) {
                $check['skipReason'] = $inspection->skipReason;
            }
            return [
                'check' => $check,
                'tables' => [],
                'pages' => [],
            ];
        }
        $check = [
            'position' => $position,
            'class' => (new \ReflectionClass($className))->getShortName(),
            'className' => $className,
            'title' => $inspection->description->title,
            'tags' => $inspection->description->tags,
            'status' => $inspection->status->value,
            'preliminary' => $preliminary,
            'durationMs' => $inspection->durationMs,
            'peakMemory' => $inspection->peakMemory,
            'description' => implode(' ', $inspection->description->lines),
        ];
        $findings = $this->filterFindings($inspection->findings, $options);
        if ($options->hasFilter()) {
            $check['filterMatches'] = $findings !== [];
            if ($findings === []) {
                // Still listed: The check is not ok, which explains the exit code and the status of later checks
                $check['counts'] = ['findings' => 0, 'total' => count($inspection->findings)];
                return [
                    'check' => $check,
                    'tables' => [],
                    'pages' => [],
                ];
            }
        }
        $tables = [];
        foreach ($findings as $finding) {
            $tables[$this->getKeyTableName($finding)] = true;
            foreach ($finding->changes as $change) {
                $tables[$change->tableName] = true;
            }
        }
        $check['keyTypes'] = array_values(array_unique(array_map(fn(Finding $finding): string => $this->getKeyType($finding), $findings)));
        $check['counts'] = $this->buildCounts($findings) + ['total' => count($inspection->findings)];
        $built = $this->buildFindings($className, $findings, $options, $findingWriter);
        $check['facets'] = $built['facets'];
        $check['groups'] = $built['groups'];
        if (!$options->isSummary()) {
            $check['truncated'] = $built['count'] < count($findings);
            if ($findingWriter === null) {
                $check['findings'] = $built['findings'];
            }
        }
        return [
            'check' => $check,
            'tables' => array_keys($tables),
            'pages' => $built['pages'],
        ];
    }

    /**
     * TCA roles and row count of each table that occurs in findings or changes.
     *
     * @param list<string> $tableNames
     * @return array<string, array<string, mixed>>
     */
    public function buildTables(array $tableNames): array
    {
        $tables = [];
        foreach ($tableNames as $tableName) {
            $isTcaTable = is_array($GLOBALS['TCA'][$tableName] ?? false);
            $tables[$tableName] = [
                'tca' => $isTcaTable,
                'rowCount' => $this->recordReader->countRows($tableName),
                'roles' => $isTcaTable ? (object)$this->recordReader->getRoles($tableName) : new \stdClass(),
            ];
            $inlineParents = $isTcaTable ? $this->tcaHelper->getInlineParentsOfTable($tableName) : [];
            if ($inlineParents !== []) {
                $tables[$tableName]['inlineParents'] = array_map(
                    static fn(array $parent): array => [
                        'table' => $parent['tableName'],
                        'field' => $parent['fieldName'],
                        'foreignField' => $parent['foreignField'],
                        'foreignTableField' => $parent['foreignTableField'],
                        'foreignMatchFields' => (object)$parent['foreignMatchFields'],
                    ],
                    $inlineParents
                );
            }
        }
        ksort($tables);
        return $tables;
    }

    /**
     * Findings matching the drill-down filters of the options.
     *
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function filterFindings(array $findings, ReportOptions $options): array
    {
        $statements = new PreparedStatements();
        try {
            $subtreeByPid = [];
            $filtered = [];
            foreach ($findings as $finding) {
                $key = $finding->key;
                if ($options->filterTable !== null && $this->getKeyTableName($finding) !== $options->filterTable) {
                    continue;
                }
                if ($options->filterUid !== null && ($key instanceof MmKey ? $key->uidLocal : $key->uid) !== $options->filterUid) {
                    continue;
                }
                if ($options->filterPid !== null && $finding->pid !== $options->filterPid) {
                    continue;
                }
                if ($options->filterSubtree !== null) {
                    if ($finding->pid === null) {
                        continue;
                    }
                    $subtreeByPid[$finding->pid] ??= in_array(
                        $options->filterSubtree,
                        array_map(static fn(array $page): int => (int)$page['uid'], $this->pagesRootlineHelper->getRootline($statements, $finding->pid)),
                        true
                    );
                    if (!$subtreeByPid[$finding->pid]) {
                        continue;
                    }
                }
                $filtered[] = $finding;
            }
            return $filtered;
        } finally {
            $statements->release();
        }
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, mixed>
     */
    private function buildCounts(array $findings): array
    {
        $records = [];
        $writes = 0;
        $findingsByTable = [];
        // Affected rows per table: Records of record and record field findings, a record with
        // several broken fields is one row. MM findings of a missing local record have their rows
        // as evidence.
        $rowsByTable = [];
        $mmRowsByTable = [];
        foreach ($findings as $finding) {
            $key = $finding->key;
            $tableName = $this->getKeyTableName($finding);
            if ($key instanceof MmKey) {
                $mmRowsByTable[$tableName] = ($mmRowsByTable[$tableName] ?? 0) + (int)($finding->evidence['mmRowCount'] ?? 1);
            } else {
                $records[$key->tableName . ':' . $key->uid] = true;
                $rowsByTable[$tableName][$key->uid] = true;
            }
            $writes += count($finding->changes);
            $findingsByTable[$tableName] = ($findingsByTable[$tableName] ?? 0) + 1;
        }
        $byTableCounts = [];
        foreach ($findingsByTable as $tableName => $count) {
            $rowCount = $this->recordReader->countRows($tableName);
            $rows = count($rowsByTable[$tableName] ?? []) + ($mmRowsByTable[$tableName] ?? 0);
            $byTableCounts[$tableName] = [
                'findings' => $count,
                'rows' => $rows,
                'share' => $rowCount > 0 ? round($rows / $rowCount, 5) : null,
                // A check flagging most rows of a table hints to a mismatch of check and TCA, not to broken data
                'suspicious' => $rows > self::SUSPICIOUS_MIN_ROWS && $rowCount > 0 && $rows / $rowCount > self::SUSPICIOUS_SHARE,
            ];
        }
        return [
            'findings' => count($findings),
            'records' => count($records),
            'writes' => $writes,
            'byTable' => (object)$byTableCounts,
        ];
    }

    /**
     * Findings up to the limit, the pages of their records, and facets and groups over all
     * findings. Records are read one by one and only kept for findings up to the limit. With a
     * writer, the findings are passed to it one by one and not kept at all.
     *
     * @param list<Finding> $findings
     * @param \Closure(array<string, mixed>): void|null $findingWriter
     * @return array{findings: list<array<string, mixed>>, count: int, facets: array<string, mixed>, groups: list<array<string, mixed>>, pages: array<int, array<string, mixed>>}
     */
    private function buildFindings(string $className, array $findings, ReportOptions $options, ?\Closure $findingWriter): array
    {
        // Written fields per table: Read with the record, their current value is the old value of the change
        $writtenFields = [];
        foreach ($findings as $finding) {
            foreach ($finding->changes as $change) {
                if ($change->type === RecordChangeType::Update || $change->type === RecordChangeType::SoftDelete) {
                    foreach (array_keys($change->values) as $field) {
                        $writtenFields[$change->tableName][$field] = true;
                    }
                }
            }
        }
        $facets = [
            'operation' => [],
            'reason' => [],
            'state' => [],
            'hidden' => [],
            'language' => [],
            'tstampAge' => [],
            'stateByOperation' => [],
            'rootPage' => [],
            'writePatterns' => [],
        ];
        $siteRootPageIds = [];
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            $siteRootPageIds[$site->getRootPageId()] = true;
        }
        // Rootline per pid, a check often has many findings on the same pages
        $rootlines = [];
        // Pages of the findings in the result, with their rootline
        $pages = [];
        $groups = [];
        $statements = new PreparedStatements();
        try {
            $result = [];
            $count = 0;
            $sequence = 0;
            foreach ($findings as $index => $finding) {
                $key = $finding->key;
                $record = null;
                if (!$key instanceof MmKey) {
                    $record = $this->recordReader->read($statements, $key->tableName, $key->uid, array_keys($writtenFields[$key->tableName] ?? []), $options->tier);
                }
                $facets = $this->addToFacets($facets, $finding, $record);
                $rootline = null;
                if ($finding->pid !== null) {
                    $rootlines[$finding->pid] ??= $this->buildRootline($statements, $finding->pid, $siteRootPageIds, $options);
                    $rootline = $rootlines[$finding->pid];
                    $rootPage = $rootline['rootPageId'] === null ? 'none' : (string)$rootline['rootPageId'];
                    $facets['rootPage'][$rootPage] = ($facets['rootPage'][$rootPage] ?? 0) + 1;
                }
                $id = substr(sha1($className . '|' . json_encode($this->buildKey($finding), JSON_THROW_ON_ERROR)), 0, 16);
                $isInResult = $options->limit === null || $index < $options->limit;
                $groups = $this->addToGroups($groups, $finding, $record, $rootline, $isInResult ? $id : null);
                if (!$isInResult) {
                    continue;
                }
                if ($rootline !== null && $options->context === ReportContext::Basic) {
                    $pages[$finding->pid] = $rootline;
                }
                $writes = [];
                foreach ($finding->changes as $change) {
                    $sequence++;
                    $writes[] = $this->buildWrite($statements, $sequence, $change, $key, $record, $options);
                }
                $built = [
                    'id' => $id,
                    'key' => $this->buildKey($finding),
                    'pid' => $finding->pid,
                    'reason' => $finding->reason === null ? null : [
                        'code' => $finding->reason->code,
                        'message' => $finding->reason->message,
                        'parameters' => (object)$finding->reason->parameters,
                    ],
                ];
                if ($finding->evidence !== []) {
                    $built['evidence'] = $finding->evidence;
                }
                $built['record'] = $record === null ? null : $this->withoutFields($record);
                $built['writes'] = $writes;
                if ($options->context === ReportContext::Basic) {
                    $built['context'] = $this->buildContext($statements, $finding, $record, $options);
                }
                $count++;
                if ($findingWriter === null) {
                    $result[] = $built;
                } else {
                    $findingWriter($built);
                }
            }
        } finally {
            $statements->release();
        }
        ksort($facets['writePatterns']);
        $facets['writePatterns'] = array_values($facets['writePatterns']);
        foreach (['operation', 'reason', 'state', 'hidden', 'language', 'tstampAge', 'stateByOperation', 'rootPage'] as $facetName) {
            $facets[$facetName] = (object)$facets[$facetName];
        }
        // Largest groups first
        uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        return [
            'findings' => $result,
            'count' => $count,
            'facets' => $facets,
            'groups' => array_values($groups),
            'pages' => $pages,
        ];
    }

    /**
     * Count a finding in its group: Findings of the same table, reason, record state and
     * visibility, operations, and whether their page is in a site. A group keeps the ids of
     * its first findings in the result as samples.
     *
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed>|null $record
     * @param array{uid: int, rootline: list<array<string, mixed>>, rootPageId: int|null, translations: list<array<string, int|bool>>}|null $rootline
     * @return array<string, array<string, mixed>>
     */
    private function addToGroups(array $groups, Finding $finding, ?array $record, ?array $rootline, ?string $id): array
    {
        $operations = array_values(array_unique(array_map(static fn(RecordChange $change): string => $change->type->value, $finding->changes)));
        sort($operations);
        $group = [
            'table' => $this->getKeyTableName($finding),
            'reason' => $finding->reason->code ?? 'none',
            'state' => $this->getState($record),
            'hidden' => $record !== null && array_key_exists('hidden', $record) ? ($record['hidden'] === 1 ? 'hidden' : 'visible') : null,
            'operation' => implode('+', $operations),
            'inSite' => $rootline === null ? null : $rootline['rootPageId'] !== null,
        ];
        $groupKey = json_encode($group, JSON_THROW_ON_ERROR);
        $groups[$groupKey] ??= $group + ['count' => 0, 'samples' => []];
        $groups[$groupKey]['count']++;
        if ($id !== null && count($groups[$groupKey]['samples']) < self::GROUP_SAMPLES) {
            $groups[$groupKey]['samples'][] = $id;
        }
        return $groups;
    }

    /**
     * State of the record of a finding: live, deleted, workspace, or none without record.
     *
     * @param array<string, mixed>|null $record
     */
    private function getState(?array $record): string
    {
        if ($record === null) {
            return 'none';
        }
        if (($record['deleted'] ?? 0) === 1) {
            return 'deleted';
        }
        if (($record['workspace']['id'] ?? 0) > 0) {
            return 'workspace';
        }
        return 'live';
    }

    /**
     * Rootline of a page from the tree root down to the page with the doktype of each page,
     * the site root page, and the translations of the page. Missing, soft-deleted and hidden
     * pages are flagged, titles only with tier "labels".
     *
     * @param array<int, true> $siteRootPageIds
     * @return array{uid: int, rootline: list<array<string, mixed>>, rootPageId: int|null, translations: list<array<string, int|bool>>}
     */
    private function buildRootline(PreparedStatements $statements, int $pid, array $siteRootPageIds, ReportOptions $options): array
    {
        $rootline = [];
        $rootPageId = null;
        foreach ($this->pagesRootlineHelper->getRootline($statements, $pid) as $page) {
            $entry = ['uid' => (int)$page['uid']];
            if ((int)$page['uid'] > 0 && !(bool)$page['_isMissing']) {
                $entry['doktype'] = (int)$page['doktype'];
            }
            if ((bool)$page['_isMissing']) {
                $entry['missing'] = true;
            } elseif ((bool)$page['deleted']) {
                $entry['deleted'] = true;
            }
            if ((bool)$page['hidden']) {
                $entry['hidden'] = true;
            }
            if (!(bool)$page['_isMissing'] && (int)$page['t3ver_wsid'] > 0) {
                $entry['workspace'] = (int)$page['t3ver_wsid'];
            }
            if ($options->tier === ReportTier::Labels && !(bool)$page['_isMissing']) {
                $entry['title'] = $this->recordReader->shortenLabel((string)$page['title']);
            }
            if (isset($siteRootPageIds[(int)$page['uid']])) {
                $rootPageId = (int)$page['uid'];
            }
            $rootline[] = $entry;
        }
        return [
            'uid' => $pid,
            'rootline' => $rootline,
            'rootPageId' => $rootPageId,
            'translations' => $this->recordReader->readPageTranslations($statements, $pid),
        ];
    }

    /**
     * Related records of a finding: Language parent and translation source of its record,
     * the translation source only if it is not the language parent. The rootline of its page
     * is in the pages of the report.
     *
     * @param array<string, mixed>|null $record
     * @return object Map of related records, an object in JSON also when empty
     */
    private function buildContext(PreparedStatements $statements, Finding $finding, ?array $record, ReportOptions $options): object
    {
        $context = [];
        $key = $finding->key;
        if ($record !== null && !$key instanceof MmKey) {
            foreach (['l10nParent', 'l10nSource'] as $role) {
                $uid = (int)($record[$role] ?? 0);
                if ($role === 'l10nSource' && $uid === (int)($record['l10nParent'] ?? 0)) {
                    continue;
                }
                if ($uid > 0) {
                    $related = $this->recordReader->read($statements, $key->tableName, $uid, [], $options->tier);
                    $context[$role] = $related === null ? ['uid' => $uid, 'missing' => true] : $this->withoutFields($related);
                }
            }
        }
        return (object)$context;
    }

    /**
     * Count a finding in the facets: Operations of its changes, its reason code, state, visibility,
     * language and age of its record, and patterns of its writes.
     *
     * @param array<string, mixed> $facets
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    private function addToFacets(array $facets, Finding $finding, ?array $record): array
    {
        $state = $this->getState($record);
        if ($record !== null) {
            if (array_key_exists('hidden', $record)) {
                $hidden = $record['hidden'] === 1 ? 'hidden' : 'visible';
                $facets['hidden'][$hidden] = ($facets['hidden'][$hidden] ?? 0) + 1;
            }
            if (array_key_exists('language', $record)) {
                $language = (string)$record['language'];
                $facets['language'][$language] = ($facets['language'][$language] ?? 0) + 1;
            }
            $age = $this->getAgeBucket((int)($record['tstamp'] ?? 0));
            $facets['tstampAge'][$age] = ($facets['tstampAge'][$age] ?? 0) + 1;
        }
        $facets['state'][$state] = ($facets['state'][$state] ?? 0) + 1;
        $reason = $finding->reason->code ?? 'none';
        $facets['reason'][$reason] = ($facets['reason'][$reason] ?? 0) + 1;
        foreach ($finding->changes as $change) {
            $operation = $change->type->value;
            $facets['operation'][$operation] = ($facets['operation'][$operation] ?? 0) + 1;
            $facets['stateByOperation'][$state][$operation] = ($facets['stateByOperation'][$state][$operation] ?? 0) + 1;
            $patterns = [['operation' => $operation, 'table' => $change->tableName]];
            if ($change->type === RecordChangeType::Update || $change->type === RecordChangeType::SoftDelete) {
                $patterns = [];
                foreach ($change->values as $field => $valueAndType) {
                    $patterns[] = [
                        'operation' => $operation,
                        'table' => $change->tableName,
                        'field' => $field,
                        'value' => $this->getValueClass($valueAndType['value'], $record),
                    ];
                }
            }
            foreach ($patterns as $pattern) {
                $patternKey = implode('|', $pattern);
                $facets['writePatterns'][$patternKey] ??= $pattern + ['count' => 0];
                $facets['writePatterns'][$patternKey]['count']++;
            }
        }
        return $facets;
    }

    /**
     * Kind of a new value: zero, one, empty string, the value of a field of the record like
     * "role:l10nParent" (l10n_source := l10n_parent), or another value.
     *
     * @param array<string, mixed>|null $record
     */
    private function getValueClass(int|string $value, ?array $record): string
    {
        if ($value === 0 || $value === '0') {
            return '0';
        }
        if ($value === 1 || $value === '1') {
            return '1';
        }
        if ($value === '') {
            return 'empty';
        }
        foreach (['pid', 'l10nParent', 'l10nSource', 'language'] as $role) {
            if ($record !== null && array_key_exists($role, $record) && (string)$record[$role] === (string)$value) {
                return 'role:' . $role;
            }
        }
        return 'value';
    }

    /**
     * Age of a timestamp relative to the time of the run, "none" without timestamp.
     */
    private function getAgeBucket(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'none';
        }
        $age = (int)$GLOBALS['EXEC_TIME'] - $timestamp;
        return match (true) {
            $age < 30 * 86400 => '<30d',
            $age < 365 * 86400 => '<1y',
            $age < 5 * 365 * 86400 => '<5y',
            default => 'older',
        };
    }

    /**
     * @param array<string, mixed>|null $keyRecord
     * @return array<string, mixed>
     */
    private function buildWrite(PreparedStatements $statements, int $sequence, RecordChange $change, RecordKey|RecordFieldKey|MmKey $key, ?array $keyRecord, ReportOptions $options): array
    {
        $write = [
            'seq' => $sequence,
            'operation' => $change->type->value,
            'table' => $change->tableName,
        ];
        if ($change->uid !== null) {
            $write['uid'] = $change->uid;
        }
        switch ($change->type) {
            case RecordChangeType::Update:
            case RecordChangeType::SoftDelete:
                $target = $keyRecord;
                if ($key instanceof MmKey || $key->tableName !== $change->tableName || $key->uid !== $change->uid) {
                    $target = $this->recordReader->read($statements, $change->tableName, (int)$change->uid, array_keys($change->values), $options->tier);
                }
                $set = [];
                foreach ($change->values as $field => $valueAndType) {
                    $set[$field] = [
                        'old' => $target['fields'][$field] ?? null,
                        'new' => $valueAndType['value'],
                    ];
                }
                $write['set'] = $set;
                break;
            case RecordChangeType::Insert:
                $write['values'] = array_map(static fn(array $valueAndType): int|string => $valueAndType['value'], $change->values);
                break;
            case RecordChangeType::MmDelete:
                $write['where'] = array_map(static fn(array $valueAndType): int|string => $valueAndType['value'], $change->where);
                break;
            case RecordChangeType::Delete:
                break;
        }
        return $write;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildKey(Finding $finding): array
    {
        $key = $finding->key;
        return match (true) {
            $key instanceof RecordKey => ['type' => 'record', 'table' => $key->tableName, 'uid' => $key->uid],
            $key instanceof RecordFieldKey => ['type' => 'record-field', 'table' => $key->tableName, 'uid' => $key->uid, 'field' => $key->fieldName],
            $key instanceof MmKey => [
                'type' => 'mm',
                'mmTable' => $key->mmTableName,
                'uidLocal' => $key->uidLocal,
                'localTable' => $key->localTableName,
                'field' => $key->localFieldName,
                'matchFields' => (object)$key->matchFields,
            ],
        };
    }

    private function getKeyType(Finding $finding): string
    {
        return match (true) {
            $finding->key instanceof RecordKey => 'record',
            $finding->key instanceof RecordFieldKey => 'record-field',
            $finding->key instanceof MmKey => 'mm',
        };
    }

    private function getKeyTableName(Finding $finding): string
    {
        return $finding->key instanceof MmKey ? $finding->key->mmTableName : $finding->key->tableName;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function withoutFields(array $record): array
    {
        unset($record['fields']);
        return $record;
    }
}
