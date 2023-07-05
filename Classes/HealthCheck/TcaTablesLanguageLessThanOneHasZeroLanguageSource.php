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

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records with sys_language_uid = 0 (or -1) must have l10n_source=0
 */
final class TcaTablesLanguageLessThanOneHasZeroLanguageSource extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records in default language not having language source zero');
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'TCA records in default or "all" language (typically sys_language_uid field having 0 or -1)',
            'must have their "translationSource" (typically l10n_source) field set to zero (0).',
            'This checks finds and updates violating records.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageSourceAwareTcaTable() as $tableName) {
            $sysLanguageField = $this->tcaHelper->getLanguageField($tableName);
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $translationSourceField = $this->tcaHelper->getTranslationSourceField($tableName);
            if ($sysLanguageField === null || $translationParentField === null || $translationSourceField === null) {
                throw new \RuntimeException(
                    'TCA ctrl languageField or translationSource or transOrigPointerField null, indicates bug in getNextLanguageSourceAwareTcaTable()',
                    1688575780
                );
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Let's deal with deleted=1 records here as well.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $sysLanguageField, $translationParentField, $translationSourceField)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->lte($sysLanguageField, 0),
                    $queryBuilder->expr()->neq($translationSourceField, 0)
                )
                ->orderBy('uid');
            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRows[$tableName][] = $row;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            $translationSourceField = $this->tcaHelper->getTranslationSourceField($tableName);
            $updateFields = [
                $translationSourceField => [
                    'value' => 0,
                    'type' => \PDO::PARAM_INT,
                ],
            ];
            $this->updateTcaRecordsOfTable($io, $simulate, $tableName, $tableRows, $updateFields);
        }
    }
}
