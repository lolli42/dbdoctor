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
 * Which checks a structured report runs, option "--scope".
 */
enum ReportScope: string
{
    // Run checks until the first one that is not ok, later checks are not run
    case UntilFirstFinding = 'until-first-finding';
    // Run all checks, results after the first not ok check are preliminary
    case All = 'all';
}
