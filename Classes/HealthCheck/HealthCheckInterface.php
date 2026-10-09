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

use Symfony\Component\Console\Style\SymfonyStyle;

interface HealthCheckInterface
{
    public const MODE_INTERACTIVE = 0;
    public const MODE_CHECK = 1;
    public const MODE_EXECUTE = 2;

    /** @var int Bitmask - No changes needed */
    public const RESULT_OK = 0;
    /** @var int Bitmask - Changes needed or done */
    public const RESULT_BROKEN = 1;
    /** @var int Bitmask - User abort */
    public const RESULT_ABORT = 2;
    /** @var int Bitmask - Error occurred */
    public const RESULT_ERROR = 4;

    /**
     * Section title, class name, actions and description of the check. AbstractHealthCheck->handle()
     * calls this only if there are affected records or output is verbose (-v), checks without
     * affected records are a single line otherwise. Checks not extending AbstractHealthCheck call
     * it in their handle() themselves.
     */
    public function header(SymfonyStyle $io): void;
    public function handle(SymfonyStyle $io, int $mode, string $file): int;
}
