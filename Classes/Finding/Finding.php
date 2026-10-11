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

/**
 * Something a health check found broken, and the changes that fix it.
 *
 * One fix per finding: The changes are the one way dbdoctor handles it. Most findings have
 * a single change, a finding of a group field with MM table has one change per removed relation.
 * Changes are executed in the order of findings, and in their order within a finding.
 */
final readonly class Finding
{
    /**
     * @param int|null $pid Page the record is located on, null if the finding is on no page, for instance MM rows of a missing record
     * @param list<RecordChange> $changes
     * @param array<string, mixed> $evidence Check specific details, named, documented by the check
     */
    public function __construct(
        public RecordKey|RecordFieldKey|MmKey $key,
        public ?int $pid,
        public array $changes,
        public ?Reason $reason = null,
        public array $evidence = [],
    ) {}
}
