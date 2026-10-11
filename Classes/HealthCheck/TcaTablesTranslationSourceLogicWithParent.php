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
 * Translated records with transOrigPointerField (typically l10n_parent) and translationSource
 * (typically l10n_source) not zero and both values different: The record has been translated from
 * a different translation, not from the default language record. That translation must have the
 * same transOrigPointerField value as the handled record.
 */
final readonly class TcaTablesTranslationSourceLogicWithParent extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for translated records with logically wrong translation source',
            [self::TAG_UPDATE],
            [
                'When "transOrigPointerField" (typically l10n_parent) and "translationSource" (typically',
                'l10n_source) of a translated record are not zero but point to different uids, it indicates',
                'the record has been derived from a different language record and not from the default language',
                'record. That different language record should have the same "transOrigPointerField" value. If',
                'this is not the case, set the translation source to the value of "transOrigPointerField" to fix',
                'the inheritance chain. Records pointing to themselves as language parent are skipped.',
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
                    $translationSourceRecord = $this->recordsHelper->getRecord($run->statements, $tableName, ['uid', $translationParentField], (int)$row[$translationSourceField]);
                } catch (NoSuchRecordException) {
                    // Not existing translation source is handled by TcaTablesTranslationSourceExists,
                    // the record is found in the next run if that check only simulated.
                    continue;
                }
                // The parent of the translation source must be the parent of the handled record.
                $translationSourceParent = (int)$translationSourceRecord[$translationParentField];
                if ($translationSourceParent !== (int)$row[$translationParentField]) {
                    $translationSourceUid = (int)$row[$translationSourceField];
                    $findings[] = new Finding(
                        new RecordKey($tableName, (int)$row['uid']),
                        (int)$row['pid'],
                        [
                            new RecordChange(RecordChangeType::Update, $tableName, (int)$row['uid'], [
                                $translationSourceField => [
                                    'value' => (int)$row[$translationParentField],
                                    'type' => Connection::PARAM_INT,
                                ],
                            ]),
                        ],
                        new Reason(
                            'source-parent-different',
                            'Translation source "' . $translationSourceUid . '" has language parent "' . $translationSourceParent . '"',
                            ['translationSource' => $translationSourceUid, 'translationSourceParent' => $translationSourceParent]
                        ),
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
                1791463203
            );
        }
        return [$languageField, $translationParentField, $translationSourceField];
    }
}
