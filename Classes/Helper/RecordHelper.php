<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Helper;

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

use TYPO3\CMS\Core\Database\ConnectionPool;

class RecordHelper
{
    protected ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getRecordDetailsForTable(string $tableName, array $recordUids, array $primaryFields = []): array
    {
        $rows = [];
        $rows[] = ['foo', 'bar'];
        $rows[] = ['foo', 'baz'];
        return $rows;
    }
}
