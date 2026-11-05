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
 * One schema change statement suggested by the core schema migrator.
 */
final readonly class SchemaChange
{
    public function __construct(
        public SchemaChangeCategory $category,
        public string $connectionName,
        // Core identifier of the statement, md5 of it
        public string $hash,
        public string $statement,
        // Changed: current definition of the changed column
        public ?string $currentDefinition = null,
        // Unused and obsolete tables: number of rows
        public ?int $rowCount = null,
    ) {}
}
