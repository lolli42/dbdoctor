<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\StructuredOutput;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICELolli\Dbdoctor\StructuredOutputE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Which related records of findings a structured report contains, option "--context".
 */
enum ReportContext: string
{
    // No related records
    case None = 'none';
    // Language parent and translation source of a record, rootline of its page
    case Basic = 'basic';
}
