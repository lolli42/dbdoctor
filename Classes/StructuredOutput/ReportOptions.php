<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\StructuredOutput;

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
 * Options of a structured report, see HealthCommand --format json.
 */
final readonly class ReportOptions
{
    /**
     * @param int|null $limit Maximum number of findings per check, 0 for none, null for all. Counts and facets are always complete.
     * @param string|null $filterTable Only findings of this table: The record table, or the MM table
     * @param int|null $filterUid Only findings of this record uid, with $filterTable
     * @param int|null $filterPid Only findings on this page
     * @param int|null $filterSubtree Only findings on this page and its sub pages
     */
    public function __construct(
        public ReportScope $scope,
        public ReportTier $tier,
        public ?int $limit,
        public ReportContext $context = ReportContext::Basic,
        public ?string $filterTable = null,
        public ?int $filterUid = null,
        public ?int $filterPid = null,
        public ?int $filterSubtree = null,
    ) {}

    /**
     * Summary: Counts and facets of the checks only, without findings and their record data.
     */
    public function isSummary(): bool
    {
        return $this->limit === 0;
    }

    public function hasFilter(): bool
    {
        return $this->filterTable !== null || $this->filterUid !== null || $this->filterPid !== null || $this->filterSubtree !== null;
    }
}
