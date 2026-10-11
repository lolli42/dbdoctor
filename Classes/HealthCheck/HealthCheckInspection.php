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

use Lolli\Dbdoctor\Finding\Finding;

/**
 * Result of a read-only run of a health check, see HealthCheckRunner->inspect().
 */
final readonly class HealthCheckInspection
{
    /**
     * @param list<Finding> $findings
     * @param string|null $skipReason Message of the EarlierCheckNotFixedException of a skipped check
     */
    public function __construct(
        public HealthCheckDescription $description,
        public InspectionStatus $status,
        public array $findings,
        public ?string $skipReason,
        public int $durationMs,
        public int $peakMemory,
    ) {}
}
