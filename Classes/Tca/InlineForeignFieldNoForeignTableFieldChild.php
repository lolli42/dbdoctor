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
 * A child table of a TCA type "inline" field with "foreign_field" but without
 * "foreign_table_field": The child rows store the parent uid only.
 * See TcaHelper->getNextInlineForeignFieldNoForeignTableFieldChildTcaTable().
 */
final readonly class InlineForeignFieldNoForeignTableFieldChild
{
    public function __construct(
        public string $tableName,
        public string $parentTableName,
        public string $fieldNameOfParentTableUid,
    ) {}
}
