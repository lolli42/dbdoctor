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
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Check if translated records point to existing records.
 */
class TcaTablesInvalidLanguageParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations with invalid parent');
        $io->text([
            '[DELETE] Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            '         database field "transOrigPointerField" (DB field name usually "l10n_parent" or "l18n_parent").',
            '         This field points to a default language record. This health check verifies if that target',
            '         exists in the database, is on the same page, and the deleted flag is in sync. Having "dangling"',
            '         localized records on a page can otherwise trigger various issue when the page is copied or similar.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $danglingRows = [];
        foreach ($tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            /** @var string $languageField */
            $languageField = $tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $tcaHelper->getTranslationParentField($tableName);
            $deletedField = $tcaHelper->getDeletedField($tableName);

            $parentRowFields = [
                'uid',
                'pid',
                $languageField,
            ];
            if ($deletedField) {
                $parentRowFields[] = $deletedField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // Query could be potentially optimized with a self-join, but well ...
            $result = $queryBuilder->select('uid', 'pid', $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                $broken = false;
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ($deletedField && (int)$parentRow[$deletedField] === 1) {
                        $broken = true;
                        $localizedRow['_reasonBroken'] = 'Parent set to deleted';
                    } elseif ((int)$localizedRow['pid'] !== (int)$parentRow['pid']) {
                        $broken = true;
                        $localizedRow['_reasonBroken'] = 'Parent is on different page';
                    }
                } catch (NoSuchRecordException $e) {
                    $broken = true;
                    $localizedRow['_reasonBroken'] = 'Missing parent';
                }
                if ($broken) {
                    $danglingRows[$tableName][(int)$localizedRow['uid']] = $localizedRow;
                }
            }
        }
        return $danglingRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteAllRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '_reasonBroken');
    }
}
