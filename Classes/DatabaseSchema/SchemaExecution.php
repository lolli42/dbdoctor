<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DatabaseSchema;

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
 * Result of executing schema change statements: Execution stops at the first failing statement.
 */
final readonly class SchemaExecution
{
    public function __construct(
        public int $executedCount,
        public ?SchemaChange $failedChange = null,
        public string $errorMessage = '',
    ) {}
}
