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
 * LICELolli\Dbdoctor\HealthCheckE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Status of a read-only run of a health check, see HealthCheckRunner->inspect().
 */
enum InspectionStatus: string
{
    case Ok = 'ok';
    case Findings = 'findings';
    // The check relies on findings of an earlier check being fixed
    case Skipped = 'skipped';
}
