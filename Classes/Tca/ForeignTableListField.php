<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tca;

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
 * A TCA field that stores relations to records of its "foreign_table" as comma separated
 * uid list in the field itself, without MM table: Type "select" with "foreign_table", type
 * "category", and type "inline" without "foreign_field". See TcaHelper->getNextSelectFieldWithoutMm()
 * and getNextInlineFieldWithUidList().
 */
final readonly class ForeignTableListField
{
    /**
     * $allowedTables has the single table of TCA "foreign_table". Record types may change it
     * with TCA "columnsOverrides", as for GroupField. Use TcaHelper->getAllowedTablesOfRecordType()
     * for a record.
     *
     * @param array<int, string> $allowedTables The table of TCA "foreign_table"
     * @param array<int, string> $staticItemValues Values of TCA "items" of select fields, of all record types
     * @param array<array-key, array<int, string>|null> $allowedTablesByType
     */
    public function __construct(
        public string $tableName,
        public string $fieldName,
        public array $allowedTables,
        public array $staticItemValues = [],
        public ?string $typeField = null,
        public array $allowedTablesByType = [],
    ) {}
}
