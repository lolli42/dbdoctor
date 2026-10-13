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
 * Each sys_file record has exactly one live default language sys_file_metadata record. When there are
 * more, core MetaDataRepository->findByFileUid() uses the one with the lowest uid, the others are never
 * used. A default record may also be an "all languages" record (sys_language_uid -1), as in findByFileUid().
 * The duplicates are removed, sys_file_metadata is not soft-delete aware. Translations pointing to a removed
 * duplicate are connected to the kept record by SysFileMetadataTranslatedParentInvalid, which runs later
 * and uses the lowest uid as well.
 */
final readonly class SysFileMetadataDefaultDuplicates extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for duplicate default language sys_file_metadata records',
            [ActionTag::Remove],
            [
                'Each "sys_file" record has one default language "sys_file_metadata" record. When there are',
                'more, the core uses the one with the lowest uid, the others are never used. This check removes',
                'them. Translations pointing to a removed record are connected to the kept one by a later check.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        // Join live default language records of the same file with a lower uid: Each row having such
        // a record is a duplicate, the lowest uid of them is the record core uses.
        $result = $queryBuilder
            ->select('duplicate.uid', 'duplicate.pid', 'duplicate.file')
            ->addSelectLiteral($expr->min('kept.uid', 'kept_uid'))
            ->from('sys_file_metadata', 'duplicate')
            ->join(
                'duplicate',
                'sys_file_metadata',
                'kept',
                (string)$expr->and(
                    $expr->eq('kept.file', $queryBuilder->quoteIdentifier('duplicate.file')),
                    $expr->lt('kept.uid', $queryBuilder->quoteIdentifier('duplicate.uid')),
                    $expr->in('kept.sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
                    $expr->eq('kept.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
            )
            ->where(
                $expr->in('duplicate.sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
                $expr->eq('duplicate.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('duplicate.uid', 'duplicate.pid', 'duplicate.file')
            ->orderBy('duplicate.uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $keptUid = (int)$row['kept_uid'];
            $findings[] = new Finding(
                new RecordKey('sys_file_metadata', (int)$row['uid']),
                (int)$row['pid'],
                [new RecordChange(RecordChangeType::Delete, 'sys_file_metadata', (int)$row['uid'])],
                new Reason('duplicate', 'Duplicate of kept record "' . $keptUid . '"', ['kept' => $keptUid]),
            );
        }
        return $findings;
    }

    public function getDetailCtrlFields(string $tableName): array
    {
        return ['languageField'];
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['file'];
    }
}
