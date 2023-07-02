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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tables with not-deleted record translations must point to not-deleted records in transOrigPointerField
 */
class TcaTablesTranslatedLanguageParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for not-deleted record translations with deleted parent');
        $io->text([
            '[UPDATE] Record translations use the TCA ctrl field "transOrigPointerField"',
            '         (DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
            '         default language record. This health check verifies that target is not deleted=1 in the database.',
            '         Affected records are set to deleted=1 if in live, or removed if in workspaces.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
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
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow[$deletedField] === 1) {
                        $affectedRows[$tableName][] = $localizedRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new \RuntimeException(
                        'Record with uid="' . $localizedRow['uid'] . '" on table "' . $tableName . '"'
                        . ' has ' . $translationParentField . '="' . $localizedRow[$translationParentField] . '", but that record does not exist.'
                        . ' A previous check should have found and fixed this. Please repeat.',
                        1648031985
                    );
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->softOrHardDeleteRecords($io, $simulate, $tableName, $tableRows);
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
