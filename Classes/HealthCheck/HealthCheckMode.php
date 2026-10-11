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
 * How health checks are run, option "--mode" of the command.
 */
enum HealthCheckMode
{
    // Ask per check with findings what to do
    case Interactive;
    // Report findings, change nothing
    case Check;
    // Execute all changes
    case Execute;
}
