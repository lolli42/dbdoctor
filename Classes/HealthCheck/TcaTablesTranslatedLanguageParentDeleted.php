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
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tables with not-deleted record translations must point to not-deleted records in transOrigPointerField
 *
 * The table specific variants for tt_content and sys_file_reference run earlier in the chain,
 * this check is the fallback for them. pages has its own checks and is excluded.
 */
final readonly class TcaTablesTranslatedLanguageParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for not-deleted record translations with deleted parent',
            [self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE],
            [
                'Record translations use the TCA ctrl field "transOrigPointerField"',
                '(DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
                'default language record. This health check verifies the target is not deleted=1.',
                'Affected records are set to deleted=1 if in live, or removed if in workspaces.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            $deletedField = $this->tcaHelper->getDeletedField($tableName);
            if (!$deletedField) {
                // Ignore tables without delete field.
                continue;
            }

            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);

            $selectFields = [
                'uid',
                'pid',
                $translationParentField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $parentRowFields = [
                'uid',
                'pid',
                $deletedField,
            ];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Do not fetch deleted=1 records
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

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $this->recordsHelper->getRecord($run->statements, $tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow[$deletedField] === 1) {
                        $findings[] = new Finding(
                            new RecordKey($tableName, (int)$localizedRow['uid']),
                            (int)$localizedRow['pid'],
                            [$this->softOrHardDeleteChange($tableName, $localizedRow)],
                            new Reason(
                                'parent-deleted',
                                'Language parent "' . $localizedRow[$translationParentField] . '" is soft-deleted',
                                [$translationParentField => (int)$localizedRow[$translationParentField]]
                            ),
                        );
                    }
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new EarlierCheckNotFixedException(
                        'Record with uid="' . $localizedRow['uid'] . '" on table "' . $tableName . '"'
                        . ' has ' . $translationParentField . '="' . $localizedRow[$translationParentField] . '", but that record does not exist.'
                        . ' An earlier check finds and fixes this.',
                        1648031985
                    );
                }
            }
        }
        return $findings;
    }

    protected function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }
}
