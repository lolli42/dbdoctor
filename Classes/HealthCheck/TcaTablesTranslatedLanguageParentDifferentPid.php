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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tables with record translations must have their pid set to the same pid the default language record points to.
 *
 * The table specific variant for tt_content runs earlier in the chain, this check is the
 * fallback for it. pages has its own checks and is excluded, sys_file_reference is excluded
 * since this fix would be wrong for it, see below.
 */
final readonly class TcaTablesTranslatedLanguageParentDifferentPid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for record translations on wrong pid',
            [self::TAG_UPDATE, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE],
            [
                'Record translations use the TCA ctrl field "transOrigPointerField"',
                '(DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
                'default language record. This health check verifies translated records are on',
                'the same pid as the default language record. It will move, hide or remove affected',
                'records, which depends on potentially existing localizations on the target page.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $findings = [];
        // @todo: sys_file_reference is excluded due to https://github.com/lolli42/dbdoctor/issues/30, see comment below, too.
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages', 'sys_file_reference']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $hiddenField = $this->tcaHelper->getHiddenField($tableName);

            $selectFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
                $selectFields[] = 't3ver_state';
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not fetch deleted=1 records if table is soft-delete aware.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();

            // Translations this check moves to a target pid without deleting them, as
            // [targetPid][language][parent] => list of workspace ids. A later translation of the
            // same parent and language finds them as existing translation on the target pid.
            $movedTranslations = [];
            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $this->recordsHelper->getRecord($run->statements, $tableName, ['uid', 'pid'], (int)$localizedRow[$translationParentField]);
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new EarlierCheckNotFixedException(
                        'Record with uid="' . $localizedRow['uid'] . '" on table "' . $tableName . '"'
                        . ' has ' . $translationParentField . '="' . $localizedRow[$translationParentField] . '", but that record does not exist.'
                        . ' An earlier check finds and fixes this.',
                        1648031986
                    );
                }
                $parentPid = (int)$parentRow['pid'];
                if ($parentPid === (int)$localizedRow['pid']
                    // Ignore "workspace moved" translations due to the odd l10n_parent behavior, as
                    // shown with the tests from https://review.typo3.org/c/Packages/TYPO3.CMS/+/89803
                    // @todo: This could be optimized when the underlying core question has been decided.
                    || ($isTableWorkspaceAware && (int)$localizedRow[$workspaceIdField] > 0 && (int)$localizedRow['t3ver_state'] === 4)
                ) {
                    continue;
                }
                $change = $this->getChange($tableName, $localizedRow, $parentPid, $movedTranslations);
                if ($hiddenField !== null && isset($change->values[$hiddenField])) {
                    // Moved and hidden, it is a not deleted translation on the target pid now.
                    $workspaceId = $isTableWorkspaceAware ? (int)$localizedRow[$workspaceIdField] : 0;
                    $movedTranslations[$parentPid][(int)$localizedRow[$languageField]][(int)$localizedRow[$translationParentField]][] = $workspaceId;
                }
                $findings[] = new Finding(
                    new RecordKey($tableName, (int)$localizedRow['uid']),
                    (int)$localizedRow['pid'],
                    [$change],
                    new Reason(
                        'parent-different-pid',
                        'Language parent "' . $localizedRow[$translationParentField] . '" is on page "' . $parentPid . '"',
                        ['parent' => (int)$localizedRow[$translationParentField], 'parentPid' => $parentPid]
                    ),
                );
            }
        }
        return $findings;
    }

    public function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }

    /**
     * Move the translation to the pid of its language parent. Moving may show it in frontend, it is
     * hidden then. If a translation exists on the target pid already, the moved one is deleted.
     *
     * @param array<string, int|string> $localizedRow
     * @param array<int, array<int, array<int, list<int>>>> $movedTranslations
     */
    private function getChange(string $tableName, array $localizedRow, int $parentPid, array $movedTranslations): RecordChange
    {
        $deleteField = $this->tcaHelper->getDeletedField($tableName);
        $isTableSoftDeleteAware = !empty($deleteField);
        $hiddenField = $this->tcaHelper->getHiddenField($tableName);
        /** @var string $languageField */
        $languageField = $this->tcaHelper->getLanguageField($tableName);
        /** @var string $translationParentField */
        $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        $isTableWorkspaceAware = !empty($workspaceIdField);
        $isWorkspaceRecord = $isTableWorkspaceAware && (int)$localizedRow[$workspaceIdField] > 0;
        $uid = (int)$localizedRow['uid'];
        $pidValue = [
            'value' => $parentPid,
            'type' => Connection::PARAM_INT,
        ];
        $flagValue = [
            'value' => 1,
            'type' => Connection::PARAM_INT,
        ];

        if ($this->hasTranslationOnTargetPid($tableName, $localizedRow, $parentPid, $movedTranslations)) {
            // If there is a localized row already, we set the wrong-pid row to correct pid and set deleted=1 for delete-aware tables, or remove it
            if (!$isTableSoftDeleteAware || $isWorkspaceRecord) {
                return new RecordChange(RecordChangeType::Delete, $tableName, $uid);
            }
            return new RecordChange(RecordChangeType::Update, $tableName, $uid, ['pid' => $pidValue, $deleteField => $flagValue]);
        }
        // @todo: sys_file_reference is excluded due to https://github.com/lolli42/dbdoctor/issues/30, see comment above, too.
        // pid for sys_file_reference is not *that* important: If it's not correct, the record translation will
        // still be shown, those could simply be moved to the correct pid. Maybe split this special handling to
        // an own check, maybe merge with PagesTranslatedLanguageParentDifferentPid?
        // For all other tables: Moving this record means it may show up in the FE now. To avoid this,
        // if the table is 'hidden'-aware, we set the record to hidden and move it. If the table is not
        // hidden aware but delete-aware, we set the record to deleted=1. Else, we remove the record.
        if (!empty($hiddenField)) {
            return new RecordChange(RecordChangeType::Update, $tableName, $uid, ['pid' => $pidValue, $hiddenField => $flagValue]);
        }
        if (!$isTableSoftDeleteAware || $isWorkspaceRecord) {
            return new RecordChange(RecordChangeType::Delete, $tableName, $uid);
        }
        return new RecordChange(RecordChangeType::Update, $tableName, $uid, ['pid' => $pidValue, $deleteField => $flagValue]);
    }

    /**
     * A not deleted translation of the same parent and language on the target pid: In the database,
     * or moved there by this check before. A workspace record sees live records and records of its
     * workspace, a live record sees live records.
     *
     * @param array<string, int|string> $localizedRow
     * @param array<int, array<int, array<int, list<int>>>> $movedTranslations
     */
    private function hasTranslationOnTargetPid(string $tableName, array $localizedRow, int $parentPid, array $movedTranslations): bool
    {
        /** @var string $languageField */
        $languageField = $this->tcaHelper->getLanguageField($tableName);
        /** @var string $translationParentField */
        $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
        $isTableWorkspaceAware = !empty($workspaceIdField);
        $workspaceId = $isTableWorkspaceAware ? (int)$localizedRow[$workspaceIdField] : 0;

        $movedWorkspaceIds = $movedTranslations[$parentPid][(int)$localizedRow[$languageField]][(int)$localizedRow[$translationParentField]] ?? [];
        foreach ($movedWorkspaceIds as $movedWorkspaceId) {
            if (!$isTableWorkspaceAware || $movedWorkspaceId === 0 || $movedWorkspaceId === $workspaceId) {
                return true;
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('uid')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentPid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($localizedRow[$languageField], Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($localizedRow[$translationParentField], Connection::PARAM_INT))
            );
        if ($isTableWorkspaceAware && $workspaceId > 0) {
            // If the localized record that is on the wrong pid is a workspace record, check if there is
            // a localized live OR this-workspace record on the target pid.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                )
            );
        } elseif ($isTableWorkspaceAware) {
            // If the localized record that is on the wrong pid is a live record in a workspace aware table,
            // check for existing localized records in live on the target pid.
            $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        return count($queryBuilder->executeQuery()->fetchAllAssociative()) > 0;
    }
}
