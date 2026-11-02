<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tca;

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
 * A TCA table with "languageField" and "transOrigPointerField" in ctrl.
 * See TcaHelper->getNextLanguageAwareTcaTable().
 */
final readonly class LanguageAwareTable
{
    /**
     * @param non-empty-string $languageField
     * @param non-empty-string $translationParentField
     */
    public function __construct(
        public string $tableName,
        public string $languageField,
        public string $translationParentField,
    ) {}
}
