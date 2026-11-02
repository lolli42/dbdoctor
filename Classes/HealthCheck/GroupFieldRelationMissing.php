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
use Lolli\Dbdoctor\Tca\GroupField;

/**
 * Relations in TCA type 'group' fields without MM table must point to existing records.
 */
final readonly class GroupFieldRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ListRelationHelper $listRelationHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for group fields with relations to missing records',
            [ActionTag::UpdateFields],
            [
                'Fields of TCA type "group" without MM table store their relations as comma',
                'separated list, for instance the tt_content field "records" of content type',
                '"Insert records". This check finds relations to records that do not exist',
                'and removes them from the list. Relations to soft-deleted records are kept:',
                'The backend does not remove them when a record is deleted, and they are',
                'needed when a record is restored using the recycler. Record types that change',
                'the allowed tables of a field with "columnsOverrides" are taken into account.',
            ],
        );
    }

    public function detect(PreparedStatements $statements): array
    {
        return $this->listRelationHelper->getFindings($statements, $this->getGroupFields(), RelationProblem::Missing);
    }

    /**
     * @return iterable<GroupField>
     */
    private function getGroupFields(): iterable
    {
        foreach ($this->tcaHelper->getNextGroupFieldWithoutMm() as $groupField) {
            if ($groupField->tableName === 'sys_file_reference' && $groupField->fieldName === 'uid_local') {
                // The earlier check SysFileReferenceDangling removes file references to missing files.
                // Checking them again here costs one query per file reference, and emptying uid_local
                // would leave a broken reference. If SysFileReferenceDangling is disabled, they are kept.
                continue;
            }
            yield $groupField;
        }
    }
}
