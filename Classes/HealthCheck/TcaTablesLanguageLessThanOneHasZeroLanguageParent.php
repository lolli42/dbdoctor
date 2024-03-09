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
use TYPO3\CMS\Core\Database\Connection;

/**
 * Records with sys_language_uid = 0 (or -1) must have l10n_parent=0
 */
final class TcaTablesLanguageLessThanOneHasZeroLanguageParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records in default language not having language parent zero');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'TCA records in default or "all" language (typically sys_language_uid field having 0 or -1)',
            'must have their "transOrigPointerField" (typically l10n_parent or l18n_parent) field',
            'set to zero (0). This checks finds and updates violating records.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            $sysLanguageField = $this->tcaHelper->getLanguageField($tableName);
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            if ($sysLanguageField === null || $translationParentField === null) {
                throw new \RuntimeException(
                    'TCA ctrl languageField or transOrigPointerField null, indicates bug in getNextLanguageAwareTcaTable()',
                    1688571126
                );
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Let's deal with deleted=1 records here as well.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $sysLanguageField, $translationParentField)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->lte($sysLanguageField, 0),
                    $queryBuilder->expr()->neq($translationParentField, 0)
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
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $updateFields = [
                $translationParentField => [
                    'value' => 0,
                    'type' => Connection::PARAM_INT,
                ],
            ];
            $this->updateTcaRecordsOfTable($io, $simulate, $tableName, $tableRows, $updateFields);
        }
    }
}
