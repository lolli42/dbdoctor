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
 * A TCA field of type "group" that stores its relations in an MM table, as
 * local side of the relation. See TcaHelper->getNextGroupFieldWithMm().
 */
final readonly class GroupMmField
{
    /**
     * @param array<int, string> $allowedTables Tables of TCA "allowed", "*" for any table
     * @param array<string, int|string> $matchFields TCA "MM_match_fields"
     */
    public function __construct(
        public string $tableName,
        public string $fieldName,
        public string $mmTableName,
        public array $allowedTables,
        public array $matchFields,
    ) {}
}
