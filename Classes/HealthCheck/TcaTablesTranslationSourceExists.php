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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;

/**
 * TCA ctrl translationSource (typically l10n_source) must point to an existing record.
 */
final readonly class TcaTablesTranslationSourceExists extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for translated records with not existing translation source',
            [self::TAG_UPDATE],
            [
                'When the "translationSource" field (typically l10n_source) of a translated record is not zero,',
                'the target record must exist. A broken translation source especially confuses the "Translate"',
                'button in page module. The translation source of affected records is set to the value of the',
                '"transOrigPointerField" (typically l10n_parent) if set, to zero otherwise.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
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
                    $this->recordsHelper->getRecord($run->statements, $tableName, ['uid'], (int)$row[$translationSourceField]);
                } catch (NoSuchRecordException) {
                    $translationSourceUid = (int)$row[$translationSourceField];
                    $findings[] = new Finding(
                        new RecordKey($tableName, (int)$row['uid']),
                        (int)$row['pid'],
                        [
                            new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], [
                                $translationSourceField => [
                                    'value' => max((int)$row[$translationParentField], 0),
                                    'type' => Connection::PARAM_INT,
                                ],
                            ]),
                        ],
                        new Reason('source-missing', 'Translation source "' . $translationSourceUid . '" does not exist', ['translationSource' => $translationSourceUid]),
                    );
                }
            }
        }
        return $findings;
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
