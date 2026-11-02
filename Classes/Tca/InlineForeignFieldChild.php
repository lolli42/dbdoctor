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
 * A child table of TCA type "inline" or "file" fields with "foreign_field" and
 * "foreign_table_field": The child rows store parent uid and parent table name.
 * See TcaHelper->getNextInlineForeignFieldChildTcaTable().
 */
final readonly class InlineForeignFieldChild
{
    public function __construct(
        public string $tableName,
        public string $fieldNameOfParentTableUid,
        public string $fieldNameOfParentTableName,
    ) {}
}
