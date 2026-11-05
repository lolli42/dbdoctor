<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DatabaseSchema;

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

use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * Compare the database schema with the table definitions of ext_tables.sql files and TCA,
 * as the install tool "Analyze Database Structure" does.
 *
 * The core schema migrator returns statements per connection, grouped by keys:
 * - Without $remove: "create_table" and "add" (missing), "change" and "change_table" (changed),
 *   "change_currentValue" holds the current definition of "change" statements.
 * - With $remove: "change" and "change_table" rename to "zzz_deleted_*" (unused), "drop" and
 *   "drop_table" (obsolete), "tables_count" holds row counts of renamed and dropped tables.
 */
final readonly class SchemaInspector
{
    public function __construct(
        private SqlReader $sqlReader,
        private SchemaMigrator $schemaMigrator,
    ) {}

    /**
     * @return list<SchemaChange> Ordered by category
     */
    public function getChanges(): array
    {
        $statements = $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString());
        $add = $this->schemaMigrator->getUpdateSuggestions($statements);
        $remove = $this->schemaMigrator->getUpdateSuggestions($statements, true);
        $missing = $changed = $unused = $obsolete = [];
        foreach ($add as $connectionName => $suggestions) {
            $missing[] = $this->createChanges(SchemaChangeCategory::Missing, $connectionName, $suggestions['create_table'] ?? []);
            $missing[] = $this->createChanges(SchemaChangeCategory::Missing, $connectionName, $suggestions['add'] ?? []);
            $changed[] = $this->createChanges(SchemaChangeCategory::Changed, $connectionName, $suggestions['change'] ?? [], $suggestions['change_currentValue'] ?? []);
            $changed[] = $this->createChanges(SchemaChangeCategory::Changed, $connectionName, $suggestions['change_table'] ?? []);
        }
        foreach ($remove as $connectionName => $suggestions) {
            $rowCounts = $suggestions['tables_count'] ?? [];
            $unused[] = $this->createChanges(SchemaChangeCategory::Unused, $connectionName, $suggestions['change'] ?? []);
            $unused[] = $this->createChanges(SchemaChangeCategory::Unused, $connectionName, $suggestions['change_table'] ?? [], [], $rowCounts);
            $obsolete[] = $this->createChanges(SchemaChangeCategory::Obsolete, $connectionName, $suggestions['drop'] ?? []);
            $obsolete[] = $this->createChanges(SchemaChangeCategory::Obsolete, $connectionName, $suggestions['drop_table'] ?? [], [], $rowCounts);
        }
        return array_merge(...$missing, ...$changed, ...$unused, ...$obsolete);
    }

    public function isInLevel(SchemaChangeCategory $category, SchemaLevel $level): bool
    {
        return match ($level) {
            SchemaLevel::Required => $category === SchemaChangeCategory::Missing,
            SchemaLevel::Recommended => $category === SchemaChangeCategory::Missing || $category === SchemaChangeCategory::Changed,
            SchemaLevel::All => true,
        };
    }

    /**
     * @param array<string, string> $statements
     * @param array<string, string> $currentDefinitions
     * @param array<string, int> $rowCounts
     * @return list<SchemaChange>
     */
    private function createChanges(SchemaChangeCategory $category, string $connectionName, array $statements, array $currentDefinitions = [], array $rowCounts = []): array
    {
        $changes = [];
        foreach ($statements as $hash => $statement) {
            $changes[] = new SchemaChange(
                $category,
                $connectionName,
                (string)$hash,
                $statement,
                $currentDefinitions[$hash] ?? null,
                isset($rowCounts[$hash]) ? (int)$rowCounts[$hash] : null,
            );
        }
        return $changes;
    }
}
