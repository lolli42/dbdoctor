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
 * A TCA field of type "group" that stores its relations as comma separated
 * list in the field itself, without MM table. See TcaHelper->getNextGroupFieldWithoutMm().
 */
final readonly class GroupField
{
    /**
     * Record types may change "allowed" of the field with TCA "columnsOverrides", see
     * TcaHelper->getAllowedTablesByType(). $typeField is only set if they do, then
     * $allowedTablesByType has the allowed tables of these types, null for types whose
     * relations are left alone. Keys are type values, numeric ones are integers.
     * Use TcaHelper->getAllowedTablesOfRecordType() for a record.
     *
     * @param array<int, string> $allowedTables Tables of TCA "allowed", "*" for any table
     * @param array<array-key, array<int, string>|null> $allowedTablesByType
     */
    public function __construct(
        public string $tableName,
        public string $fieldName,
        public array $allowedTables,
        public ?string $typeField = null,
        public array $allowedTablesByType = [],
    ) {}
}
