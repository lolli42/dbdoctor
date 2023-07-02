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

/**
 * Tables with record translations must point to existing records in transOrigPointerField
 */
final class TcaTablesTranslatedLanguageParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations with missing parent');
        $io->text([
            '[DELETE] Record translations use the TCA ctrl field "transOrigPointerField"',
            '         (DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
            '         default language record. This health check verifies if that target exists in the database.',
            '         Affected records without language parent are removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records too: If their language parent is gone, they shouldn't exist anymore, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $recordsHelper->getRecord($tableName, ['uid'], (int)$localizedRow[$translationParentField]);
                } catch (NoSuchRecordException $e) {
                    $affectedRows[$tableName][(int)$localizedRow['uid']] = $localizedRow;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
