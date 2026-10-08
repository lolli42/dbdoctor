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
use TYPO3\CMS\Core\Database\Connection;

/**
 * TCA ctrl translationSource (typically l10n_source) must point to an existing record.
 */
final class TcaTablesTranslationSourceExists extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with not existing translation source');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'When the "translationSource" field (typically l10n_source) of a translated record is not zero,',
            'the target record must exist. A broken translation source especially confuses the "Translate"',
            'button in page module. The translation source of affected records is set to the value of the',
            '"transOrigPointerField" (typically l10n_parent) if set, to zero otherwise.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $affectedRecords = [];
        foreach ($this->tcaHelper->getNextLanguageSourceAwareTcaTable() as $tableName) {
            [$languageField, $translationParentField, $translationSourceField] = $this->getFields($tableName);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $languageField, $translationParentField, $translationSourceField)
                ->from($tableName)
                ->where(
                    // Default language records are zero already due to TcaTablesLanguageLessThanOneHasZeroLanguageSource
                    $queryBuilder->expr()->gt($languageField, 0),
                    $queryBuilder->expr()->gt($translationSourceField, 0)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                try {
                    $recordsHelper->getRecord($tableName, ['uid'], (int)$row[$translationSourceField]);
                } catch (NoSuchRecordException) {
                    $affectedRecords[$tableName][] = $row;
                }
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $rows) {
            [, $translationParentField, $translationSourceField] = $this->getFields($tableName);
            $this->outputTableUpdateBefore($io, $simulate, $tableName);
            foreach ($rows as $row) {
                $updateFields = [
                    $translationSourceField => [
                        'value' => max((int)$row[$translationParentField], 0),
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $updateFields);
            }
            $this->outputTableUpdateAfter($io, $simulate, $tableName, count($rows));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function getFields(string $tableName): array
    {
        $languageField = $this->tcaHelper->getLanguageField($tableName);
        $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
        $translationSourceField = $this->tcaHelper->getTranslationSourceField($tableName);
        if ($languageField === null || $translationParentField === null || $translationSourceField === null) {
            throw new \RuntimeException(
                'TCA ctrl languageField or transOrigPointerField or translationSource null, indicates bug in getNextLanguageSourceAwareTcaTable()',
                1791463201
            );
        }
        return [$languageField, $translationParentField, $translationSourceField];
    }
}
