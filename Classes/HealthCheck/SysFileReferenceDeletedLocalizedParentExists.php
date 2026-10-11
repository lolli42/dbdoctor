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
 * Deleted localized sys_file_reference records must point to a sys_language_uid=0 parent that exists.
 * This is the "safe" variant of SysFileReferenceLocalizedParentExists since it handles deleted=1 records only.
 */
final readonly class SysFileReferenceDeletedLocalizedParentExists extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for deleted localized sys_file_reference records without parent',
            [self::TAG_REMOVE],
            [
                'Soft deleted localized records in "sys_file_reference" (sys_language_uid > 0) having',
                'l10n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.',
                'Records violating this are removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l10n_parent')->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('deleted', 1),
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l10n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $this->recordsHelper->getRecord($run->statements, 'sys_file_reference', ['uid'], (int)$row['l10n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                // Match if parent does not exist at all
                $findings[] = new Finding(
                    new RecordKey('sys_file_reference', (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, 'sys_file_reference', (int)$row['uid'])],
                    new Reason('parent-missing', 'Language parent "' . $row['l10n_parent'] . '" does not exist', ['parent' => (int)$row['l10n_parent']]),
                );
            }
        }
        return $findings;
    }

    protected function getDetailDbFields(string $tableName): array
    {
        return ['sys_language_uid', 'l10n_parent', 'deleted', 'tablenames', 'uid_foreign', 'fieldname', 'uid_local'];
    }
}
