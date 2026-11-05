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
 * Kind of a schema change suggested by the core schema migrator, in the order they are applied.
 */
enum SchemaChangeCategory: string
{
    // Create a table, add a column or an index
    case Missing = 'missing';
    // Change a column, an index or table options
    case Changed = 'changed';
    // Rename a table or column without definition to "zzz_deleted_*"
    case Unused = 'unused';
    // Drop a "zzz_deleted_*" table or column, or an index without definition
    case Obsolete = 'obsolete';
}
