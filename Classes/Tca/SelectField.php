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
 * A TCA field of type "select" with "foreign_table", or of type "category", that stores
 * its relations as comma separated list in the field itself, without MM table.
 * See TcaHelper->getNextSelectFieldWithoutMm().
 */
final readonly class SelectField
{
    /**
     * $allowedTables has the single table of TCA "foreign_table". Record types may change it
     * with TCA "columnsOverrides", as for GroupField. Use TcaHelper->getAllowedTablesOfRecordType()
     * for a record.
     *
     * @param array<int, string> $allowedTables The table of TCA "foreign_table"
     * @param array<int, string> $staticItemValues Values of TCA "items", of all record types
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
