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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field children that are set to a different sys_language_uid than their parent.
 */
final class InlineForeignFieldChildrenParentLanguageDifferent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with different language than their parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE, self::TAG_RISKY);
        $io->text([
            'TCA inline foreign field child records point to a parent record. This check finds',
            'child records that have a different language than the parent record.',
            'Affected children are soft-deleted if the table is soft-delete aware, and',
            'hard deleted if not.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $childTableLanguageField = $this->tcaHelper->getLanguageField($childTableName);
            if (!$childTableLanguageField) {
                // Skip child table if it is not localizable
                continue;
            }
            $fieldNameOfParentTableName = $inlineChild['fieldNameOfParentTableName'];
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($childTableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);

            $selectFields = [
                'uid',
                'pid',
                $fieldNameOfParentTableName,
                $fieldNameOfParentTableUid,
                $childTableLanguageField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Do not consider deleted child records.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->gt($fieldNameOfParentTableUid, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq($fieldNameOfParentTableName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull($fieldNameOfParentTableName)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                if (empty($inlineChildRow[$fieldNameOfParentTableName])
                    || (int)($inlineChildRow[$fieldNameOfParentTableUid]) === 0
                    // Parent TCA table must be defined and table must exist
                    || !is_array($GLOBALS['TCA'][$inlineChildRow[$fieldNameOfParentTableName]] ?? false)
                    || !$tableHelper->tableExistsInDatabase((string)$inlineChildRow[$fieldNameOfParentTableName])
                ) {
                    // This was handled by previous InlineForeignFieldChildrenParentMissing already.
                    continue;
                }
                $parentTableName = (string)$inlineChildRow[$fieldNameOfParentTableName];
                $parentTableLanguageField = $this->tcaHelper->getLanguageField($parentTableName);
                if (!$parentTableLanguageField) {
                    // Skip if parent table is not localizable
                    continue;
                }
                try {
                    $parentRow = $recordsHelper->getRecord((string)$inlineChildRow[$fieldNameOfParentTableName], ['uid', $parentTableLanguageField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    $parentRowLanguage = (int)$parentRow[$parentTableLanguageField];
                    $childRowLanguage = (int)$inlineChildRow[$childTableLanguageField];
                    // @todo: We may need to think about l10n_parent field here as well?
                    if ($parentRowLanguage >= 0 && $childRowLanguage !== $parentRowLanguage
                        // If parent row is sys_language_uid = 0, and child row is -1, that's fine.
                        && !($parentRowLanguage === 0 && $childRowLanguage === -1)
                    ) {
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
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
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
