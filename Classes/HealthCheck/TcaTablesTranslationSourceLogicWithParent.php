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
 * Translated records with transOrigPointerField (typically l10n_parent) and translationSource
 * (typically l10n_source) not zero and both values different: The record has been translated from
 * a different translation, not from the default language record. That translation must have the
 * same transOrigPointerField value as the handled record.
 */
final class TcaTablesTranslationSourceLogicWithParent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records with logically wrong translation source');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'When "transOrigPointerField" (typically l10n_parent) and "translationSource" (typically',
            'l10n_source) of a translated record are not zero but point to different uids, it indicates',
            'the record has been derived from a different language record and not from the default language',
            'record. That different language record should have the same "transOrigPointerField" value. If',
            'this is not the case, set the translation source to the value of "transOrigPointerField" to fix',
            'the inheritance chain. Records pointing to themselves as language parent are skipped.',
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
                    $queryBuilder->expr()->gt($languageField, 0),
                    $queryBuilder->expr()->gt($translationSourceField, 0),
                    $queryBuilder->expr()->gt($translationParentField, 0),
                    $queryBuilder->expr()->neq($translationSourceField, $queryBuilder->quoteIdentifier($translationParentField)),
                    // Not if the record points to itself as language parent: That parent is broken, a translation
                    // source derived from it would point to the record itself. The ...ParentSelf checks handle these.
                    $queryBuilder->expr()->neq('uid', $queryBuilder->quoteIdentifier($translationParentField))
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                try {
                    $translationSourceRecord = $recordsHelper->getRecord($tableName, ['uid', $translationParentField], (int)$row[$translationSourceField]);
                } catch (NoSuchRecordException) {
                    // Not existing translation source is handled by TcaTablesTranslationSourceExists,
                    // the record is found in the next run if that check only simulated.
                    continue;
                }
                // The parent of the translation source must be the parent of the handled record.
                if ((int)$translationSourceRecord[$translationParentField] !== (int)$row[$translationParentField]) {
                    $affectedRecords[$tableName][] = $row;
                }
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $rows) {
            [, $translationParentField, $translationSourceField] = $this->getFields($tableName);
            $this->outputTableUpdateBefore($run, $simulate, $tableName);
            foreach ($rows as $row) {
                $updateFields = [
                    $translationSourceField => [
                        'value' => (int)$row[$translationParentField],
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($run, $simulate, $recordsHelper, $tableName, (int)$row['uid'], $updateFields);
            }
            $this->outputTableUpdateAfter($run, $simulate, $tableName, count($rows));
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
                1791463203
            );
        }
        return [$languageField, $translationParentField, $translationSourceField];
    }
}
