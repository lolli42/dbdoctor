<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

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
 * The kind of changes a health check applies, shown as "Actions" in its header.
 */
enum ActionTag: string
{
    // The check is considered risky, for instance when it may change FE output
    case Risky = 'risky';
    // The check may DELETE records
    case Remove = 'remove';
    // The check may set "deleted=1" on records
    case SoftDelete = 'soft-delete';
    // The check may DELETE workspace records
    case WorkspaceRemove = 'workspace-remove';
    // The check may UPDATE single fields of records
    case UpdateFields = 'update-fields';
    // The check may INSERT missing records
    case Insert = 'insert';
}
