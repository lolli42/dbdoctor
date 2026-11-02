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
use Lolli\Dbdoctor\Helper\RelationProblem;

/**
 * Relations in TCA type 'inline' fields with MM table must point to existing child records.
 */
final readonly class InlineFieldMmRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private MmRelationHelper $mmRelationHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline fields with MM relations to missing child records',
            [ActionTag::Remove],
            [
                'Fields of TCA type "inline" with an MM table store the relations to their child',
                'records as rows in the MM table. This check finds MM rows pointing to child records',
                'that do not exist, and MM rows of parent records that do not exist, and removes',
                'them. Relations of and to soft-deleted records are kept: The backend does not',
                'remove them when a record is deleted, and they are needed when a record is',
                'restored using the recycler. The number of children in the field of the parent',
                'record is not updated: dbdoctor ignores these count fields, see README.md.',
                'Record types that change the "foreign_table" of a field with "columnsOverrides"',
                'are taken into account.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        return $this->mmRelationHelper->getFindings($run, $this->tcaHelper->getNextInlineFieldWithMm(), RelationProblem::Missing);
    }
}
