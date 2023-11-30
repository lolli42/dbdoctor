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

use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle translated records where the field has allowLanguageSynchronization=1, the l10n_state has "Value of default
 * language" but the value differs from record of default language.
 */
final class TcaTablesTranslatedWithAllowLanguageSynchronization extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated records which should have value of default language');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'If a field has the TCA setting allowLanguageSynchronization=1 it is possible for translated records to use',
            'the value of the default language.',
            'This check finds translated records with different values and l10n_state="parent".',
            'and sets the l10n_state to "custom" for these fields.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextFieldWithAllowLanguageSynchronization() as $tableFields) {
            $table = $tableFields['tableName'];
            $field = $tableFields['fieldName'];
            if (!$tableHelper->tableExistsInDatabase($table)) {
                throw new NoSuchTableException('Table "' . $table . '" does not exist.');
            }
            // transOrigPointerField, e.g. l18n_parent
            $transOrigPointerField = $this->tcaHelper->getTranslationParentField($table);
            if (!$transOrigPointerField) {
                continue;
            }
            // languageField, e.g. sys_language_uid
            $languageField = $this->tcaHelper->getLanguageField($table);
            if (!$languageField) {
                continue;
            }
            // l10n_state
            $translationStateField = 'l10n_state';

            $selectFields = [
                $table . '.uid AS uid',
                $table . '.pid AS pid',
                $table . '.l10n_state AS l10n_state',
                $table . '.' . $field . ' AS ' . $field,
                $table . '.' . $translationStateField . ' AS ' . $translationStateField,
                $table . '.' . $languageField,
                $table . '.' . $transOrigPointerField,
                'parent.uid AS uid2',
                'parent.' . $field . ' AS ' . $field . '2',
            ];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            // Do not consider soft-deleted records
            $queryBuilder->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // get all translated records with a connected default language record and different value for field $field
            $result = $queryBuilder->select(...$selectFields)
                ->from($table)
                ->innerJoin(
                    $table,
                    $table,
                    'parent',
                    $queryBuilder->expr()->eq(
                        $table . '.' . $transOrigPointerField,
                        $queryBuilder->quoteIdentifier('parent.uid')
                    )
                )
                ->where(
                    $queryBuilder->expr()->neq(
                        $table . '.' . $languageField,
                        $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->neq(
                        $table . '.' . $transOrigPointerField,
                        $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->neq(
                        $table . '.' . $field,
                        $queryBuilder->quoteIdentifier('parent.' . $field)
                    ),
                )
                ->orderBy($table . '.uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $state = $this->getL10nStateForField((string)($row[$translationStateField] ?? ''), $field, 'parent');
                if ($state === 'parent') {
                    $affectedRow['_reasonBroken'] = sprintf('Value for field %s differs from language parent', $field);
                    $affectedRow['uid'] = (int)($row['uid']);
                    $affectedRow['pid'] = (int)($row['pid']);
                    $affectedRow['l10n_state'] = (string)($row['l10n_state'] ?? '');
                    $affectedRow['_fieldName'] = $field;
                    $affectedRows[$table][] = $affectedRow;
                }
            }
        }
        return $affectedRows;
    }

    protected function addFieldToL10nState(string $l10nState, string $field, string $value): string
    {
        /** @var array<string,string> $array */
        $array = \json_decode($l10nState, true) ?: [];
        $array[$field] = $value;
        return (string)(\json_encode($array) ?: $l10nState);
    }

    protected function getL10nStateForField(string $l10nState, string $field, string $default): string
    {
        /** @var array<string,string> $array */
        $array = \json_decode($l10nState, true) ?: [];
        return (string)($array[$field] ?? $default);
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        foreach ($affectedRecords as $table => $rows) {
            foreach ($rows as $row) {
                $uid = (int)$row['uid'];
                $field = (string)$row['_fieldName'];
                $newL10nState = $this->addFieldToL10nState((string)($row['l10n_state'] ?? ''), $field, 'custom');
                $fields = [
                    'l10n_state' => [
                        'value' => $newL10nState,
                        'type' => \PDO::PARAM_STR,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $table, $uid, $fields);
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldName'],
            ];
            $this->outputRecordDetails($io, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
