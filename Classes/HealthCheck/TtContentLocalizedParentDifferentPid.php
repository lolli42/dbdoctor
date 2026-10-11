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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Localized tt_content records must point to a sys_language_uid=0 parent that
 * exists on the same pid.
 */
final readonly class TtContentLocalizedParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for localized tt_content records with parent on different pid',
            [self::TAG_UPDATE, self::TAG_WORKSPACE_REMOVE],
            [
                'Localized records in "tt_content" (sys_language_uid > 0) having',
                'l18n_parent > 0 must point to a sys_language_uid = 0 language parent record',
                'on the same pid. Records violating this are typically still shown in FE at',
                'the correct page the l18n_parent lives on, but are shown in the BE at the',
                'wrong page. Affected records are moved to the pid of the l18n_parent record',
                'when possible, or removed in some workspace scenarios.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Soft-deleted records have been handled with TtContentDeletedLocalizedParentDifferentPid already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent', 't3ver_wsid', 't3ver_state')->from('tt_content')
            ->where(
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $languageParent = $this->recordsHelper->getRecord($run->statements, 'tt_content', ['uid', 'pid'], (int)$row['l18n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                throw new EarlierCheckNotFixedException(
                    'tt_content record with uid="' . $row['uid'] . '" has l18n_parent="' . $row['l18n_parent'] . '",'
                    . ' but that record does not exist. The earlier check TtContentLocalizedParentExists finds and fixes this.',
                    1689066707
                );
            }
            $parentPid = (int)$languageParent['pid'];
            if ((int)$row['pid'] === $parentPid
                // Ignore "workspace moved" translations due to the odd l10n_parent behavior, as
                // shown with the tests from https://review.typo3.org/c/Packages/TYPO3.CMS/+/89803
                // @todo: This could be optimized when the underlying core question has been decided.
                || ((int)$row['t3ver_wsid'] > 0 && (int)$row['t3ver_state'] === 4)
            ) {
                continue;
            }
            $findings[] = new Finding(
                new RecordKey('tt_content', (int)$row['uid']),
                (int)$row['pid'],
                [$this->getChange($row, $parentPid)],
                new Reason(
                    'parent-different-pid',
                    'Language parent "' . $row['l18n_parent'] . '" is on page "' . $parentPid . '"',
                    ['parent' => (int)$row['l18n_parent'], 'parentPid' => $parentPid]
                ),
            );
        }
        return $findings;
    }

    /**
     * @param array<string, int|string> $row
     */
    private function getChange(array $row, int $parentPid): RecordChange
    {
        $moveToParentPid = new RecordChange(RecordChangeType::Update, 'tt_content', (int)$row['uid'], [
            'pid' => [
                'value' => $parentPid,
                'type' => Connection::PARAM_INT,
            ],
        ]);
        $delete = new RecordChange(RecordChangeType::Delete, 'tt_content', (int)$row['uid']);
        if ((int)$row['t3ver_wsid'] === 0) {
            // Live record: Move localized element to correct pid
            return $moveToParentPid;
        }
        if ((int)$row['t3ver_state'] === 0) {
            // We have a "workspace changed" record that is not on the same pid as the
            // default language record. We move this as well, since ws preview shows this,
            // even if on wrong pid. Note if default lang record is *moved*, the "changed"
            // localized tt_content is turned into a "moved" record, so we don't need to deal
            // with this scenario here.
            return $moveToParentPid;
        }
        if ((int)$row['t3ver_state'] === 1) {
            // We have a "workspace new" record that is not on the same pid as the default
            // language record. Those are *not* shown in ws preview! We remove those.
            // Second scenario: First, new localize a record in workspace, then move the default
            // language record to a different pid: The "workspace new" localized record l18n_parent is
            // kept, and properly moved to the new pid. However, the new localized record is *not*
            // shown in preview, at least because the l18n_parent is wrong? This is a core bug.
            // For now, we remove those as well, since they are found here: We still have a default
            // lang live record, with localized "workspace new" l18n_parent pointing to it, but being
            // on a different pid.
            // @todo: Fine-tune this case when core bugs with "first add, then move" have been fixed.
            return $delete;
        }
        if ((int)$row['t3ver_state'] === 2) {
            // We have "delete placeholder" record that is not on the same pid as the default
            // language record. This works: The "delete placeholder" kicks in on preview, the record
            // is not shown. We move such records to the correct pid.
            // Scenario: First create delete placeholder of localized record in ws. Then move the default
            // language record to a different pid. The pid of the delete placeholder is updated as well,
            // the localized record moves along with the default language parent. However, l18n_parent of
            // the localized record is not updated to the uid of the move placeholder. This is a core bug.
            // In this case, we *remove* the "delete placeholder" record for now! This is not the perfect
            // solution. To fix this, the core needs to be fixed first, probably by taking care that when
            // creating an overlay of a default language record, that then existing localized children
            // get their l18n_parent set to the uid of the new overlay record.
            // @todo: Fine-tune this case when above described scenario is decided and fixed in core.
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', 't3ver_wsid', 't3ver_state', 't3ver_oid')->from('tt_content')->orderBy('uid');
            $defaultLangMovePlaceholder = $queryBuilder->where(
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', (int)$row['t3ver_wsid']),
                $queryBuilder->expr()->eq('t3ver_state', 4),
                $queryBuilder->expr()->eq('t3ver_oid', (int)$row['l18n_parent'])
            )->executeQuery()->fetchAllAssociative();
            return empty($defaultLangMovePlaceholder) ? $moveToParentPid : $delete;
        }
        if ((int)$row['t3ver_state'] === 4) {
            // "workspace move" record: A localized content element can only be moved around in workspaces
            // when the parent is moved. So, when a move placeholder of a localized content element exists,
            // there should be a "move placeholder" of the default language content element as well that
            // is on the same pid. If that record was published already, the pids of live default lang record
            // and localized "move placeholder" are identical and not found here.
            // As such, having a "move placeholder" of a localized content element alone indicates a bug,
            // so we remove the record.
            return $delete;
        }
        // t3ver_state -1 and 3 are from old core versions, earlier workspace checks remove them.
        throw new EarlierCheckNotFixedException(
            'tt_content record with uid="' . $row['uid'] . '" has t3ver_state="' . $row['t3ver_state'] . '".'
            . ' The earlier checks WorkspacesT3verStateMinusOne and WorkspacesT3verStateThree find and fix this.',
            1689082419
        );
    }
}
