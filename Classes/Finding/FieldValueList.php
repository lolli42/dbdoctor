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
 * A list of values of a field in the where of an mm-delete RecordChange, restricted
 * by IN(). Each value is bound as parameter of the given type.
 */
final readonly class FieldValueList
{
    /**
     * @param non-empty-list<int> $values
     */
    public function __construct(
        public array $values,
        public ParameterType $type,
    ) {}
}
