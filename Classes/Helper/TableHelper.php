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

use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Database schema details. The schema of a table is read lazily once per process and kept in
 * the core runtime cache: dbdoctor does not change the schema, and HealthCommand verifies it is
 * in sync with TCA before checks run.
 */
final readonly class TableHelper
{
    public function __construct(
        private ConnectionPool $connectionPool,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
    ) {}

    public function tableExistsInDatabase(string $tableName): bool
    {
        if (empty($tableName)) {
            return false;
        }
        return $this->getColumns($tableName) !== null;
    }

    public function fieldExistsInTable(string $tableName, string $fieldName): bool
    {
        if (empty($tableName) || empty($fieldName)) {
            return false;
        }
        return array_key_exists($fieldName, $this->getColumns($tableName) ?? []);
    }

    /**
     * True if the database column of a field is of type integer (smallint, int, bigint).
     * False if the column is of a different type, or if it does not exist.
     */
    public function fieldIsInteger(string $tableName, string $fieldName): bool
    {
        if (empty($tableName) || empty($fieldName)) {
            return false;
        }
        return ($this->getColumns($tableName) ?? [])[$fieldName] ?? false;
    }

    /**
     * Column names of a table, each with true if the column is of type integer.
     * Null if the table does not exist.
     *
     * @return array<string, bool>|null
     */
    private function getColumns(string $tableName): ?array
    {
        // Table names may come from row data, for instance sys_file_reference.tablenames,
        // and contain characters not allowed in cache identifiers.
        $cacheIdentifier = 'dbdoctor-table-columns-' . md5($tableName);
        if ($this->runtimeCache->has($cacheIdentifier)) {
            /** @var array{columns: array<string, bool>|null} $cacheEntry */
            $cacheEntry = $this->runtimeCache->get($cacheIdentifier);
            return $cacheEntry['columns'];
        }
        $schemaManager = $this->connectionPool->getConnectionForTable($tableName)->createSchemaManager();
        $columns = null;
        if ($schemaManager->tablesExist([$tableName])) {
            $columns = [];
            foreach ($schemaManager->listTableColumns($tableName) as $column) {
                $type = $column->getType();
                $columns[$column->getName()] = $type instanceof IntegerType || $type instanceof SmallIntType || $type instanceof BigIntType;
            }
        }
        $this->runtimeCache->set($cacheIdentifier, ['columns' => $columns]);
        return $columns;
    }
}
