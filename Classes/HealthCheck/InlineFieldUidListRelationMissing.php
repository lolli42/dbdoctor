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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Helper\ListRelationHelper;
use Lolli\Dbdoctor\Helper\RelationProblem;

/**
 * Relations in TCA type 'inline' fields without foreign_field and without MM table must
 * point to existing child records.
 */
final readonly class InlineFieldUidListRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ListRelationHelper $listRelationHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline fields with uid lists pointing to missing child records',
            [ActionTag::UpdateFields],
            [
                'Fields of TCA type "inline" without "foreign_field" and without MM table store',
                'the uids of their child records as comma separated list in the field. This check',
                'finds uids of child records that do not exist and removes them from the list.',
                'Relations to soft-deleted child records are kept: They are needed when a record',
                'is restored using the recycler. Child records no parent lists are not handled.',
                'Record types that change the "foreign_table" of a field with "columnsOverrides"',
                'are taken into account.',
            ],
        );
    }

    public function detect(PreparedStatements $statements): array
    {
        return $this->listRelationHelper->getFindings($this->tcaHelper->getNextInlineFieldWithUidList(), RelationProblem::Missing);
    }
}
