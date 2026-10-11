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
 * Result of running a single health check. The values are bits: The command combines the
 * results of all checks to its exit code.
 */
enum HealthCheckResult: int
{
    // No changes needed
    case Ok = 0;
    // Changes needed or done
    case Broken = 1;
    // User abort
    case Abort = 2;
    // Error occurred
    case Error = 4;
}
