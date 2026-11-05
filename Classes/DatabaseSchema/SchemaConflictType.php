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
 * Kind of rows that conflict with a schema change: Depending on the DBMS and its strict mode, the
 * change fails, or values are converted.
 */
enum SchemaConflictType: string
{
    // A column becomes NOT NULL
    case NullValues = 'null';
    // A text column becomes a number column
    case NotNumericValues = 'notNumeric';
    // A string column becomes shorter
    case TooLongValues = 'tooLong';
    // A number column becomes unsigned
    case NegativeValues = 'negative';
    // A unique index is added or an index becomes unique
    case DuplicateValues = 'duplicate';
}
