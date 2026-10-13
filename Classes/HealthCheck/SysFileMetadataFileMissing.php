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
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;

/**
 * sys_file_metadata records belong to the sys_file record in their field "file". Metadata of a file
 * that does not exist is never used, in no language and no workspace. This happens for instance when
 * sys_file rows are removed with low level queries. sys_file_metadata is not soft-delete aware, the
 * records are removed. This runs before SysFileMetadataMissing and SysFileMetadataTranslatedParentInvalid:
 * Translations of such records are removed here as well, instead of being handled by later checks.
 */
final readonly class SysFileMetadataFileMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for sys_file_metadata records of missing files',
            [ActionTag::Remove],
            [
                '"sys_file_metadata" records belong to the "sys_file" record in their field "file". This',
                'check finds metadata records in all languages and workspaces whose "file" is zero or points',
                'to a "sys_file" record that does not exist, and removes them: They are never used.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        $result = $queryBuilder->select('metadata.uid', 'metadata.pid', 'metadata.file')
            ->from('sys_file_metadata', 'metadata')
            ->leftJoin(
                'metadata',
                'sys_file',
                'file',
                $expr->eq('file.uid', $queryBuilder->quoteIdentifier('metadata.file'))
            )
            ->where($expr->isNull('file.uid'))
            ->orderBy('metadata.uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $findings[] = new Finding(
                new RecordKey('sys_file_metadata', (int)$row['uid']),
                (int)$row['pid'],
                [new RecordChange(RecordChangeType::Delete, 'sys_file_metadata', (int)$row['uid'])],
                (int)$row['file'] === 0
                    ? new Reason('file-zero', 'Field "file" is zero', ['file' => 0])
                    : new Reason('file-missing', 'File "' . $row['file'] . '" does not exist', ['file' => (int)$row['file']]),
            );
        }
        return $findings;
    }

    public function getDetailCtrlFields(string $tableName): array
    {
        return ['languageField', 'transOrigPointerField'];
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['file'];
    }
}
