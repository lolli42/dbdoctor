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
 * Why a finding is broken. The code is stable within a check and can be counted
 * and filtered, the message is for humans and contains the parameters.
 */
final readonly class Reason
{
    /**
     * @param array<string, int|string|list<int|string>> $parameters
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $parameters = [],
    ) {}
}
