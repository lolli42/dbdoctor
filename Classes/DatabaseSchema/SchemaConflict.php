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

/**
 * Rows that conflict with a schema change of a column or an index.
 */
final readonly class SchemaConflict
{
    /**
     * @param list<string> $columnNames One column, or the columns of a unique index
     */
    public function __construct(
        public SchemaConflictType $type,
        public SchemaChangeCategory $category,
        public string $connectionName,
        public string $tableName,
        public array $columnNames,
        public int $rowCount,
        // TooLongValues: the new maximum length, NotNumericValues: the new type, DuplicateValues: the index name
        public string $expected = '',
    ) {}
}
