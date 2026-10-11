<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Finding;

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

enum RecordChangeType: string
{
    case Update = 'update';
    // UPDATE of the delete field only
    case SoftDelete = 'soft-delete';
    case Delete = 'delete';
    case Insert = 'insert';
    // DELETE of MM rows identified by "where" fields: All rows of a relation, also duplicates of fields with "multiple"
    case MmDelete = 'mm-delete';
}
