<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

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
 * What relation helpers look for: Relations to records that do not exist, or relations of live
 * records to workspace overlays. See ListRelationHelper and MmRelationHelper.
 */
enum RelationProblem
{
    // The target record does not exist.
    case Missing;
    // A live record points to a workspace overlay (t3ver_oid > 0) instead of its live record.
    case WorkspaceOverlay;
}
