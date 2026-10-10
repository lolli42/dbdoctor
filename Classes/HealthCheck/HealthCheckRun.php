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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Data of a single handle() call of a health check. Created by AbstractHealthCheck->handle()
 * and handed down, so the health check services themselves stay stateless.
 */
final readonly class HealthCheckRun
{
    /**
     * @param string $sqlDumpFile Absolute, not-empty file path when sql commands should be logged.
     */
    public function __construct(
        public SymfonyStyle $io,
        public string $sqlDumpFile,
        public PreparedStatements $statements,
    ) {}
}
