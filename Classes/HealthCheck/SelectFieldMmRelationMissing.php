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

use Lolli\Dbdoctor\Helper\MmRelationHelper;

/**
 * Relations in TCA type 'select' fields with foreign_table and MM table must point to existing records.
 */
final readonly class SelectFieldMmRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private MmRelationHelper $mmRelationHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for select fields with MM relations to missing records',
            [ActionTag::Remove],
            [
                'Fields of TCA type "select" with a "foreign_table" and an MM table store their',
                'relations as rows in the MM table. This check finds MM rows pointing to records',
                'that do not exist, and MM rows of local records that do not exist, and removes',
                'them. Relations of and to soft-deleted records are kept: The backend does not',
                'remove them when a record is deleted, and they are needed when a record is',
                'restored using the recycler. The number of relations in the field of the local',
                'record is not updated: dbdoctor ignores these count fields, see README.md.',
                'Record types that change the "foreign_table" of a field with "columnsOverrides"',
                'are taken into account.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        return $this->mmRelationHelper->getFindings($run, $this->tcaHelper->getNextSelectFieldWithMm());
    }
}
