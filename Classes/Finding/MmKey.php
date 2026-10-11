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
 * A finding on MM rows whose local record does not exist. The rows are identified
 * by uid_local and the match fields of the local field: MM tables have a uid column
 * only for fields with "multiple", and all rows of the local record are concerned.
 */
final readonly class MmKey
{
    /**
     * @param array<string, int|string> $matchFields
     */
    public function __construct(
        public string $mmTableName,
        public int $uidLocal,
        public string $localTableName,
        public string $localFieldName,
        public array $matchFields,
    ) {}
}
