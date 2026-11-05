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
 * Which schema change categories are relevant.
 */
enum SchemaLevel: string
{
    // Missing only: dbdoctor checks can not run without
    case Required = 'required';
    // Missing and changed: the schema matches the definitions afterwards
    case Recommended = 'recommended';
    // Additionally unused and obsolete: rename and drop
    case All = 'all';
}
