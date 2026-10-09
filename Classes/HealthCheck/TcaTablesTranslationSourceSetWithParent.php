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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;

/**
 * TCA ctrl translationSource (typically l10n_source) must be set if transOrigPointerField
 * (typically l10n_parent) is not zero.
 */
final class TcaTablesTranslationSourceSetWithParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with parent but without translation source');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'Translated records with a language parent ("connected mode", "transOrigPointerField",',
            'typically l10n_parent) should have their "translationSource" (typically l10n_source) set,',
            'the backend uses it to show where a translation came from. Records translated with old',
            'TYPO3 versions or created by imports often have zero there, so this check may find many',
            'records. Setting the translation source to the language parent is what the core does',
            'when translating from the default language, the frontend output does not change.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
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
                    $queryBuilder->expr()->eq($translationSourceField, 0),
                    $queryBuilder->expr()->gt($translationParentField, 0)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRecords[$tableName][] = $row;
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
                        'value' => (int)$row[$translationParentField],
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
                1791463202
            );
        }
        return [$languageField, $translationParentField, $translationSourceField];
    }
}
