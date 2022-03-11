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

use TYPO3\CMS\Core\Database\ConnectionPool;

class TableHelper
{
    /**
     * @var array<string, bool>
     */
    private array $tableExistsCache = [];

    /**
     * @var array<string, bool>
     */
    private array $fieldExistsCache = [];

    private ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function tableExistsInDatabase(string $tableName): bool
    {
        if (empty($tableName)) {
            return false;
        }
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }
        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $this->tableExistsCache[$tableName] = $connection->createSchemaManager()->tablesExist($tableName);
        return $this->tableExistsCache[$tableName];
    }

    public function fieldExistsInTable(string $tableName, string $fieldName): bool
    {
        if (empty($tableName) || empty($fieldName)) {
            return false;
        }
        $cacheKey = $tableName . '-' . $fieldName;
        if (array_key_exists($cacheKey, $this->fieldExistsCache)) {
            return $this->fieldExistsCache[$cacheKey];
        }
        if (!$this->tableExistsInDatabase($tableName)) {
            return false;
        }
        $this->fieldExistsCache[$cacheKey] = false;

        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $tableColumns = $connection->createSchemaManager()->listTableColumns($tableName);
        foreach ($tableColumns as $column) {
            if ($column->getName() === $fieldName) {
                $this->fieldExistsCache[$cacheKey] = true;
                break;
            }
        }
        return $this->fieldExistsCache[$cacheKey];
    }
}
