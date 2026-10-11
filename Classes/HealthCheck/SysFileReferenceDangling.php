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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;

/**
 * sys_file_reference rows where either uid_local or uid_foreign does not exist.
 */
final readonly class SysFileReferenceDangling extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for orphan sys_file_reference records',
            [self::TAG_REMOVE],
            [
                'Basic check of sys_file_reference: Records referenced in uid_local and uid_foreign',
                'must exist, otherwise that sys_file_reference row is obsolete and removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        // We fetch deleted=1 records here, too. If it's relation is broken, they should vanish, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'uid_local', 'uid_foreign', 'tablenames')
            ->from('sys_file_reference')
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $reason = null;
            try {
                $this->recordsHelper->getRecord($run->statements, 'sys_file', ['uid'], (int)$row['uid_local']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                $reason = new Reason('file-missing', 'File "' . $row['uid_local'] . '" does not exist', ['uidLocal' => (int)$row['uid_local']]);
            }
            if ($reason === null) {
                try {
                    $this->recordsHelper->getRecord($run->statements, (string)$row['tablenames'], ['uid'], (int)$row['uid_foreign']);
                } catch (NoSuchTableException $e) {
                    $reason = (string)$row['tablenames'] === ''
                        ? new Reason('table-missing', 'Field "tablenames" is empty', ['tablenames' => ''])
                        : new Reason('table-missing', 'Table "' . $row['tablenames'] . '" does not exist', ['tablenames' => (string)$row['tablenames']]);
                } catch (NoSuchRecordException $e) {
                    $reason = new Reason(
                        'foreign-record-missing',
                        'Record "' . $row['uid_foreign'] . '" in table "' . $row['tablenames'] . '" does not exist',
                        ['tablenames' => (string)$row['tablenames'], 'uidForeign' => (int)$row['uid_foreign']]
                    );
                }
            }
            if ($reason !== null) {
                $findings[] = new Finding(
                    new RecordKey('sys_file_reference', (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, 'sys_file_reference', (int)$row['uid'])],
                    $reason,
                );
            }
        }
        return $findings;
    }

    protected function getDetailDbFields(string $tableName): array
    {
        return ['uid_local', 'tablenames', 'uid_foreign', 'fieldname'];
    }
}
