<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

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

use Lolli\Dbdoctor\Finding\Finding;

interface HealthCheckInterface
{
    /**
     * Title, action tags and description of the check.
     */
    public function getDescription(): HealthCheckDescription;

    /**
     * Find broken things, each with the changes that fix it. Detection does not change records.
     *
     * @return list<Finding>
     */
    public function detect(HealthCheckRun $run): array;

    /**
     * Ctrl fields like "transOrigPointerField" shown in record details of findings of a table,
     * additionally to the default fields.
     *
     * @return list<string>
     */
    public function getDetailCtrlFields(string $tableName): array;

    /**
     * Database fields shown in record details of findings of a table, additionally to the default
     * fields. Fields of RecordFieldKey findings are added automatically.
     *
     * @return list<string>
     */
    public function getDetailDbFields(string $tableName): array;
}
