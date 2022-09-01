<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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
use Lolli\Dbdoctor\Helper\TableHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handle inline foreign field children that are set to a different sys_language_uid than their parent.
 */
class InlineForeignFieldChildrenParentLanguageDifferent extends AbstractHealth implements HealthInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with different language than their parent');
        $io->text([
            '[UPDATE] TCA inline foreign field child records point to a parent record. This check finds',
            '         child records that have a different language than the parent record.',
            '         Affected child records are updated by setting them to the same language as the parent record.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $childTableLanguageField = $tcaHelper->getLanguageField($childTableName);
            if (!$childTableLanguageField) {
                // Skip child table if it is not localizable
                continue;
            }
            $fieldNameOfParentTableName = $inlineChild['fieldNameOfParentTableName'];
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Consider deleted child records.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $fieldNameOfParentTableName, $fieldNameOfParentTableUid, $childTableLanguageField)->from($childTableName)
                ->orderBy('uid')
                ->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                if (empty($inlineChildRow[$fieldNameOfParentTableName])
                    || (int)($inlineChildRow[$fieldNameOfParentTableUid]) === 0
                    // Parent TCA table must be defined and table must exist
                    || !is_array($GLOBALS['TCA'][$inlineChildRow[$fieldNameOfParentTableName]])
                    || !$tableHelper->tableExistsInDatabase((string)$inlineChildRow[$fieldNameOfParentTableName])
                ) {
                    // This was handled by previous InlineForeignFieldChildrenParentMissing already.
                    continue;
                }
                $parentTableName = (string)$inlineChildRow[$fieldNameOfParentTableName];
                $parentTableLanguageField = $tcaHelper->getLanguageField($parentTableName);
                if (!$parentTableLanguageField) {
                    // Skip if parent table is not localizable
                    continue;
                }
                try {
                    $parentRow = $recordsHelper->getRecord((string)$inlineChildRow[$fieldNameOfParentTableName], ['uid', $parentTableLanguageField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    $parentRowLanguage = (int)$parentRow[$parentTableLanguageField];
                    $childRowLanguage = (int)$inlineChildRow[$childTableLanguageField];
                    // @todo: We may need to think about l10n_parent field here as well?
                    if ($parentRowLanguage > 0 && $childRowLanguage !== $parentRowLanguage) {
                        $inlineChildRow['_reasonBroken'] = 'Parent record language ' . $parentRowLanguage;
                        $inlineChildRow['_fieldNameOfParentTableName'] = $fieldNameOfParentTableName;
                        $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                        $inlineChildRow['_parentRowLanguage'] = $parentRowLanguage;
                        $affectedRows[$childTableName][] = $inlineChildRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Record existence has been checked by InlineForeignFieldChildrenParentMissing already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $childTableName => $childTableRows) {
            $childLanguageField = $tcaHelper->getLanguageField($childTableName);
            foreach ($childTableRows as $childTableRow) {
                $updateFields = [
                    $childLanguageField => [
                        'value' => (int)$childTableRow['_parentRowLanguage'],
                        'type' => \PDO::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $childTableName, (int)$childTableRow['uid'], $updateFields);
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldNameOfParentTableName'],
                (string)$rows[0]['_fieldNameOfParentTableUid'],
            ];
            $this->outputRecordDetails($io, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
