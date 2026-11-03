<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Page;

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
 * A page of a rootline rendered by PagesRootlineHelper: The root with uid 0, existing
 * pages, or a page that does not exist, flagged "missing" with empty other fields.
 */
final readonly class RootlinePage
{
    public function __construct(
        public int $uid,
        public int $pid,
        public bool $missing,
        public bool $deleted,
        public bool $hidden,
        public int $doktype,
        public int $workspaceId,
        public string $title,
    ) {}
}
