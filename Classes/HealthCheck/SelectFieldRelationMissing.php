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

use Lolli\Dbdoctor\Helper\ListRelationHelper;
use Lolli\Dbdoctor\Helper\RelationProblem;
use Lolli\Dbdoctor\Tca\ForeignTableListField;

/**
 * Relations in TCA type 'select' fields with foreign_table and type 'category' fields
 * without MM table must point to existing records.
 */
final readonly class SelectFieldRelationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ListRelationHelper $listRelationHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for select fields with relations to missing records',
            [ActionTag::UpdateFields],
            [
                'Fields of TCA type "select" with a "foreign_table" and fields of type "category"',
                'without MM table store their relations as comma separated list or single uid,',
                'for instance the be_users field "usergroup". This check finds relations to records',
                'that do not exist and removes them from the list. Static items of a field, like',
                '"-1" and "-2" of the "fe_group" fields, are no relations and are kept. Relations to',
                'soft-deleted records are kept: The backend does not remove them when a record is',
                'deleted, and they are needed when a record is restored using the recycler.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        return $this->listRelationHelper->getFindings($run, $this->getSelectFields(), RelationProblem::Missing);
    }

    /**
     * @return iterable<ForeignTableListField>
     */
    private function getSelectFields(): iterable
    {
        foreach ($this->tcaHelper->getNextSelectFieldWithoutMm() as $selectField) {
            if ($selectField->tableName === 'sys_file_metadata' && $selectField->fieldName === 'file') {
                // The earlier check SysFileMetadataFileMissing removes metadata of missing files.
                // Setting the field to 0 would leave a metadata record without file.
                continue;
            }
            if ($selectField->tableName === 'sys_file' && $selectField->fieldName === 'storage') {
                // Core v13 has a foreign_table for this field. Storage 0 is the core fallback storage
                // for files outside a configured storage: Setting it would move files to that storage.
                // The earlier check SysFileStorageMissing removes files of missing storages.
                continue;
            }
            yield $selectField;
        }
    }
}
