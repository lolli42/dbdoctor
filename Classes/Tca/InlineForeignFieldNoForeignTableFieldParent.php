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
 * A parent table of a child table whose rows store the parent uid but not the
 * parent table name: The child rows of this parent are the rows having all
 * $matchFields set to their values.
 * See TcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent().
 */
final readonly class InlineForeignFieldNoForeignTableFieldParent
{
    /**
     * @param array<string, int|string> $matchFields TCA "foreign_match_fields"
     */
    public function __construct(
        public string $tableName,
        public string $parentTableName,
        public string $fieldNameOfParentTableUid,
        public array $matchFields,
    ) {}
}
