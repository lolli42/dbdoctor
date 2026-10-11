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
 * What a health check looks for and what it changes: Title, action tags and
 * description lines. Rendered as header of the check.
 */
final readonly class HealthCheckDescription
{
    /**
     * @param list<ActionTag> $tags Kind of changes the check applies
     * @param list<string> $lines Description, one string per output line
     */
    public function __construct(
        public string $title,
        public array $tags,
        public array $lines,
    ) {}
}
