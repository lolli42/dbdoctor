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

use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * CREATE TABLE statements of all ext_tables.sql files, as compared with the database schema.
 *
 * A number literal as default of a string or a non-integer number column, like "price double
 * DEFAULT 0", is turned into a string literal, "DEFAULT '0'": It is the same default. Without,
 * the core comparator suggests to change such columns again and again on mysql, mariadb and
 * sqlite: The database reports the default as string, the comparator does not normalize it.
 */
final readonly class SchemaDefinitionReader
{
    public function __construct(
        private SqlReader $sqlReader,
    ) {}

    /**
     * @return array<string>
     */
    public function getCreateTableStatements(): array
    {
        return array_map(
            $this->quoteNumberDefaults(...),
            $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString())
        );
    }

    public function quoteNumberDefaults(string $statement): string
    {
        return (string)preg_replace(
            '/(\b(?:var)?char\s*\(\s*\d+\s*\)|\b(?:double(?:\s+precision)?|float|real|decimal|numeric)(?:\s*\(\s*\d+\s*(?:,\s*\d+\s*)?\))?)'
            . '(\s+unsigned)?(\s+(?:not\s+)?null)?\s+default\s+(-?\d+(?:\.\d+)?)(?=[\s,)])/i',
            '$1$2$3 DEFAULT \'$4\'',
            $statement
        );
    }
}
