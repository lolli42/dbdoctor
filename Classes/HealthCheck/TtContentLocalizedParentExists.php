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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Localized tt_content records must point to a sys_language_uid=0 parent that exists.
 * Similar to TtContentDeletedLocalizedParentExists, but handles deleted=0 records only.
 */
final readonly class TtContentLocalizedParentExists extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Localized tt_content records without parent',
            [self::TAG_REMOVE],
            [
                'Localized records in "tt_content" (sys_language_uid > 0) having',
                'l18n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.',
                'Violating records are removed since they are typically never rendered in FE,',
                'even though the BE renders them in page module.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent')->from('tt_content')
            ->where(
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $this->recordsHelper->getRecord($run->statements, 'tt_content', ['uid'], (int)$row['l18n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                // Match if parent does not exist at all
                $findings[] = new Finding(
                    new RecordKey('tt_content', (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, 'tt_content', (int)$row['uid'])],
                    new Reason('parent-missing', 'Language parent "' . $row['l18n_parent'] . '" does not exist', ['parent' => (int)$row['l18n_parent']]),
                );
            }
        }
        return $findings;
    }
}
