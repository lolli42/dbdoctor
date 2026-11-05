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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallFloatType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * Find rows that conflict with schema changes of the core schema migrator: A column becomes
 * NOT NULL while rows are NULL, a unique index is added while rows share values, and so on.
 * Depending on the DBMS and its strict mode, executing the change fails, or values are converted
 * silently. extension:setup does not tell about failed statements at all.
 */
final readonly class SchemaConflictDetector
{
    /**
     * Longer values are never numbers: Not loaded, counted as not numeric.
     */
    private const MAX_NUMBER_LENGTH = 40;

    public function __construct(
        private SqlReader $sqlReader,
        private SchemaMigrator $schemaMigrator,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Conflicts of changes in the given level, conflicts of missing changes first.
     *
     * @return list<SchemaConflict>
     */
    public function getConflicts(SchemaLevel $level): array
    {
        $statements = $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString());
        $missing = [];
        $changed = [];
        foreach ($this->schemaMigrator->getSchemaDiffs($statements) as $connectionName => $schemaDiff) {
            $connection = $this->connectionPool->getConnectionByName($connectionName);
            foreach ($schemaDiff->getAlteredTables() as $tableDiff) {
                foreach ($tableDiff->getAddedIndexes() as $index) {
                    $missing[] = $this->getUniqueIndexConflict($connection, $connectionName, $tableDiff, $index, SchemaChangeCategory::Missing);
                }
                if ($level === SchemaLevel::Required) {
                    continue;
                }
                foreach ($tableDiff->getModifiedIndexes() as $index) {
                    $oldTable = $tableDiff->getOldTable();
                    if (!$oldTable->hasIndex($index->getName()) || !$oldTable->getIndex($index->getName())->isUnique()) {
                        $changed[] = $this->getUniqueIndexConflict($connection, $connectionName, $tableDiff, $index, SchemaChangeCategory::Changed);
                    }
                }
                foreach ($tableDiff->getChangedColumns() as $columnDiff) {
                    $changed = array_merge($changed, $this->getColumnConflicts($connection, $connectionName, $tableDiff, $columnDiff));
                }
            }
        }
        return array_values(array_filter(
            array_merge($missing, $changed),
            static fn(?SchemaConflict $conflict): bool => $conflict !== null && $conflict->rowCount > 0
        ));
    }

    /**
     * @return list<SchemaConflict>
     */
    private function getColumnConflicts(Connection $connection, string $connectionName, TableDiff $tableDiff, ColumnDiff $columnDiff): array
    {
        $old = $columnDiff->getOldColumn();
        $new = $columnDiff->getNewColumn();
        $tableName = $this->unquote($tableDiff->getOldTable()->getName());
        $columnName = $this->unquote($old->getName());
        $quotedTableName = $connection->quoteIdentifier($tableName);
        $quotedColumnName = $connection->quoteIdentifier($columnName);
        $conflicts = [];
        $platform = $connection->getDatabasePlatform();
        if ($new->getNotnull() && !$old->getNotnull()) {
            $conflicts[] = new SchemaConflict(
                SchemaConflictType::NullValues,
                SchemaChangeCategory::Changed,
                $connectionName,
                $tableName,
                [$columnName],
                (int)$connection->executeQuery('SELECT COUNT(*) FROM ' . $quotedTableName . ' WHERE ' . $quotedColumnName . ' IS NULL')->fetchOne(),
            );
        }
        if ($platform instanceof SQLitePlatform) {
            // sqlite enforces neither types nor lengths nor unsigned
            return $conflicts;
        }
        if ($this->isNumberType($new->getType()) && $this->isStringType($old->getType())) {
            $conflicts[] = new SchemaConflict(
                SchemaConflictType::NotNumericValues,
                SchemaChangeCategory::Changed,
                $connectionName,
                $tableName,
                [$columnName],
                $this->countNotNumericRows($connection, $quotedTableName, $quotedColumnName, $this->isIntegerType($new->getType())),
                Type::getTypeRegistry()->lookupName($new->getType()),
            );
        }
        $newLength = $this->getStringLength($new);
        $oldLength = $this->getStringLength($old);
        if ($newLength !== null && $this->isStringType($old->getType()) && ($oldLength === null || $oldLength > $newLength)) {
            $conflicts[] = new SchemaConflict(
                SchemaConflictType::TooLongValues,
                SchemaChangeCategory::Changed,
                $connectionName,
                $tableName,
                [$columnName],
                (int)$connection->executeQuery(
                    'SELECT COUNT(*) FROM ' . $quotedTableName
                    . ' WHERE ' . $platform->getLengthExpression($quotedColumnName) . ' > ' . $newLength
                )->fetchOne(),
                (string)$newLength,
            );
        }
        if ($platform instanceof AbstractMySQLPlatform && $new->getUnsigned() && !$old->getUnsigned() && $this->isNumberType($old->getType())) {
            $conflicts[] = new SchemaConflict(
                SchemaConflictType::NegativeValues,
                SchemaChangeCategory::Changed,
                $connectionName,
                $tableName,
                [$columnName],
                (int)$connection->executeQuery('SELECT COUNT(*) FROM ' . $quotedTableName . ' WHERE ' . $quotedColumnName . ' < 0')->fetchOne(),
            );
        }
        return $conflicts;
    }

    private function getUniqueIndexConflict(Connection $connection, string $connectionName, TableDiff $tableDiff, Index $index, SchemaChangeCategory $category): ?SchemaConflict
    {
        $oldTable = $tableDiff->getOldTable();
        if (!$index->isUnique()) {
            return null;
        }
        $columnNames = [];
        foreach ($index->getUnquotedColumns() as $columnName) {
            // Index columns may have a length like "slug(127)"
            $columnName = (string)preg_replace('/\(\d+\)$/', '', $columnName);
            if (!$oldTable->hasColumn($columnName)) {
                // The column is added along with the index, it has no values yet.
                return null;
            }
            $columnNames[] = $columnName;
        }
        $quotedColumnNames = array_map($connection->quoteIdentifier(...), $columnNames);
        $tableName = $this->unquote($oldTable->getName());
        $sql = 'SELECT SUM(cnt) FROM (SELECT COUNT(*) AS cnt FROM ' . $connection->quoteIdentifier($tableName)
            . ' WHERE ' . implode(' IS NOT NULL AND ', $quotedColumnNames) . ' IS NOT NULL'
            . ' GROUP BY ' . implode(', ', $quotedColumnNames) . ' HAVING COUNT(*) > 1) duplicates';
        return new SchemaConflict(
            SchemaConflictType::DuplicateValues,
            $category,
            $connectionName,
            $tableName,
            $columnNames,
            (int)$connection->executeQuery($sql)->fetchOne(),
            $this->unquote($index->getName()),
        );
    }

    /**
     * Values are grouped and checked in PHP: There is no portable SQL to test if a string is a
     * number. Long values are not loaded, they are not numbers anyway.
     */
    private function countNotNumericRows(Connection $connection, string $quotedTableName, string $quotedColumnName, bool $isInteger): int
    {
        $lengthExpression = $connection->getDatabasePlatform()->getLengthExpression($quotedColumnName);
        $result = $connection->executeQuery(
            'SELECT CASE WHEN ' . $lengthExpression . ' > ' . self::MAX_NUMBER_LENGTH . ' THEN \'\' ELSE ' . $quotedColumnName . ' END AS v, COUNT(*) AS cnt'
            . ' FROM ' . $quotedTableName . ' WHERE ' . $quotedColumnName . ' IS NOT NULL GROUP BY v'
        );
        $count = 0;
        while ($row = $result->fetchAssociative()) {
            $value = (string)$row['v'];
            $isNumber = $isInteger ? preg_match('/^-?\d+$/', $value) === 1 : is_numeric($value);
            if (!$isNumber) {
                $count += (int)$row['cnt'];
            }
        }
        return $count;
    }

    private function getStringLength(Column $column): ?int
    {
        if ($column->getType() instanceof StringType) {
            return $column->getLength();
        }
        return null;
    }

    private function isStringType(Type $type): bool
    {
        return $type instanceof StringType || $type instanceof TextType;
    }

    private function isNumberType(Type $type): bool
    {
        return $this->isIntegerType($type)
            || $type instanceof FloatType
            || $type instanceof SmallFloatType
            || $type instanceof DecimalType;
    }

    private function isIntegerType(Type $type): bool
    {
        return $type instanceof IntegerType
            || $type instanceof SmallIntType
            || $type instanceof BigIntType
            || $type instanceof BooleanType;
    }

    private function unquote(string $identifier): string
    {
        return trim($identifier, '`"');
    }
}
