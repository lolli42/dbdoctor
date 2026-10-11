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
 * Which record data a structured report contains, option "--tier".
 */
enum ReportTier: string
{
    // Uids, pids, TCA ctrl fields like deleted, language and workspace, timestamps, written fields
    case Structure = 'structure';
    // Additionally TCA label fields, except of tables with personal data
    case Labels = 'labels';
}
