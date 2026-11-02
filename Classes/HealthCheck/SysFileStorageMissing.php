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
use TYPO3\CMS\Core\Database\Connection;

/**
 * sys_file records belong to the sys_file_storage record in their field "storage". The core can not
 * create a file object of a storage that does not exist: ResourceFactory->createFileObject() throws
 * "A file needs to reside in a Storage", for instance when a file reference is rendered. Storage 0 is
 * the core fallback storage for files outside a configured storage, it has no record.
 *
 * Files of a storage that does not exist at all are removed, sys_file is not soft-delete aware. Files of
 * a soft-deleted storage are kept: The core soft-deletes a storage deleted in the backend and keeps its
 * files, restoring the storage with the recycler restores files, metadata and file references. Cleanup
 * jobs removing soft-deleted records, for instance the scheduler task "Remove deleted records" of ext:recycler,
 * remove the storage record first, the files are found by this check afterwards.
 *
 * This runs before SysFileMetadataFileMissing and SysFileReferenceDangling: Metadata and file references
 * of the removed files are removed in the same run.
 *
 * Core v15 makes this mess even more awful (state of core main c1bd1373ac4, 2026-11-02, may still
 * change; introduced with e2255aa8150 "Configure file storages in a file, not the database"): Storages
 * move from sys_file_storage into config/system/file-storages.yaml, with %env()% placeholders. A
 * storage is then no longer part of the database dump, but of the environment. A local copy without
 * the remote storage configured, or a production system with a missing environment variable, sees all
 * files of that storage as files of a "missing" storage, and this check would happily remove files,
 * metadata and file references of a storage that is perfectly fine elsewhere. Soft-deleted storages
 * and the recycler path are gone as well, and storage 0 is no longer valid. Do not let this check
 * loose on v15 without a new rule.
 */
final readonly class SysFileStorageMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for sys_file records of missing storages',
            [ActionTag::Remove],
            [
                '"sys_file" records belong to the "sys_file_storage" record in their field "storage". This',
                'check finds files of storages that do not exist and removes them: The core can not create',
                'file objects without storage and fails when such files are used. Storage 0 is the core',
                'fallback storage and has no record. Files of soft-deleted storages are kept, they are',
                'needed when the storage is restored using the recycler. Later checks remove metadata and',
                'file references of the removed files. If a storage record has been removed by mistake,',
                'restore it before running this check.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        $result = $queryBuilder->select('file.uid', 'file.pid', 'file.storage')
            ->from('sys_file', 'file')
            ->leftJoin(
                'file',
                'sys_file_storage',
                'storage',
                $expr->eq('storage.uid', $queryBuilder->quoteIdentifier('file.storage'))
            )
            ->where(
                $expr->gt('file.storage', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->isNull('storage.uid')
            )
            ->orderBy('file.uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $findings[] = new Finding(
                new RecordKey('sys_file', (int)$row['uid']),
                (int)$row['pid'],
                [new RecordChange(RecordChangeType::Delete, 'sys_file', (int)$row['uid'])],
                new Reason('storage-missing', 'Storage "' . $row['storage'] . '" does not exist', ['storage' => (int)$row['storage']]),
            );
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['storage', 'identifier'];
    }
}
