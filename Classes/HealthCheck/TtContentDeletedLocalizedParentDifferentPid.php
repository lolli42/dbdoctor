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

use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;

/**
 * Deleted localized tt_content records must point to a sys_language_uid=0 parent that
 * exists on the same pid.
 * This is a "safe" variant since it handles deleted=1 records only. Also see TtContentLocalizedParentDifferentPid.
 */
final readonly class TtContentDeletedLocalizedParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for soft-deleted localized tt_content records with parent on different pid',
            [self::TAG_REMOVE],
            [
                'Soft deleted localized records in "tt_content" (sys_language_uid > 0) having',
                'l18n_parent > 0 must point to a sys_language_uid = 0 language parent record',
                'on the same pid. Records violating this are removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent')->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', 1),
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $languageParent = $this->recordsHelper->getRecord($run->statements, 'tt_content', ['uid', 'pid'], (int)$row['l18n_parent']);
                // Note workspace moved records are not an issue here since we're dealing with
                // deleted=1 records here, which don't exist in workspaces.
                if ((int)$row['pid'] !== (int)$languageParent['pid']) {
                    $findings[] = new Finding(
                        new RecordKey('tt_content', (int)$row['uid']),
                        (int)$row['pid'],
                        [new RecordChange(RecordChangeType::Delete, 'tt_content', (int)$row['uid'])],
                        new Reason('parent-different-pid', 'Language parent "' . $row['l18n_parent'] . '" is on pid "' . $languageParent['pid'] . '"', ['parent' => (int)$row['l18n_parent'], 'parentPid' => (int)$languageParent['pid']]),
                    );
                }
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                throw new EarlierCheckNotFixedException(
                    'tt_content record with uid="' . $row['uid'] . '" has l18n_parent="' . $row['l18n_parent'] . '",'
                    . ' but that record does not exist. The earlier check TtContentDeletedLocalizedParentExists finds and fixes this.',
                    1688988051
                );
            }
        }
        return $findings;
    }
}
