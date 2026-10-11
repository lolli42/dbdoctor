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
    // Run checks until the first one that is not ok, later checks are not run
    public const SCOPE_UNTIL_FIRST_FINDING = 'until-first-finding';
    // Run all checks, results after the first not ok check are preliminary
    public const SCOPE_ALL = 'all';

    // Uids, pids, TCA ctrl fields like deleted, language and workspace, timestamps, written fields
    public const TIER_STRUCTURE = 'structure';
    // Additionally TCA label fields, except of tables with personal data
    public const TIER_LABELS = 'labels';

    // No related records
    public const CONTEXT_NONE = 'none';
    // Language parent and translation source of a record, rootline of its page
    public const CONTEXT_BASIC = 'basic';

    /**
     * @param int $limit Maximum number of findings per check, 0 for all. Counts are always complete.
     */
    public function __construct(
        public string $scope,
        public string $tier,
        public int $limit,
        public string $context = self::CONTEXT_BASIC,
    ) {}
}
