<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Finding;

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

use Doctrine\DBAL\ParameterType;

/**
 * A single change of a finding: One statement. Created by health checks while
 * detecting, executed or rendered by RecordChangeExecutor.
 */
final readonly class RecordChange
{
    /**
     * @param int|null $uid Record to update or delete, null for insert and mm-delete
     * @param array<string, array{value: int|string, type: ParameterType}> $values Fields to set for update, soft-delete and insert
     * @param array<string, array{value: int|string, type: ParameterType}> $where Fields identifying the rows of mm-delete
     */
    public function __construct(
        public RecordChangeType $type,
        public string $tableName,
        public ?int $uid = null,
        public array $values = [],
        public array $where = [],
    ) {}
}
