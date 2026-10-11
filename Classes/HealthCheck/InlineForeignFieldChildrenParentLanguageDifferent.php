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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field children that are set to a different sys_language_uid than their parent.
 */
final readonly class InlineForeignFieldChildrenParentLanguageDifferent extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline foreign field records with different language than their parent',
            [self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE, self::TAG_UPDATE, self::TAG_RISKY],
            [
                'TCA inline foreign field child records point to a parent record. This check finds',
                'child records that have a different language than the parent record.',
                'Affected children are soft-deleted if the table is soft-delete aware, and',
                'hard deleted if not. Children with language -1 (all languages) of a translated',
                'parent are shown in frontend along with their parent: Their language is set to',
                'the language of the parent instead.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        // Per table: Updates of children with language -1 first, then soft or hard deletes of the others.
        $updateFindings = [];
        $deleteFindings = [];
        $tableNames = [];
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
                    || !$this->tableHelper->tableExistsInDatabase((string)$inlineChildRow[$fieldNameOfParentTableName])
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
                    $parentRow = $this->recordsHelper->getRecord($run->statements, (string)$inlineChildRow[$fieldNameOfParentTableName], ['uid', $parentTableLanguageField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    $parentRowLanguage = (int)$parentRow[$parentTableLanguageField];
                    $childRowLanguage = (int)$inlineChildRow[$childTableLanguageField];
                    // @todo: We may need to think about l10n_parent field here as well?
                    if ($parentRowLanguage >= 0 && $childRowLanguage !== $parentRowLanguage
                        // If parent row is sys_language_uid = 0, and child row is -1, that's fine.
                        && !($parentRowLanguage === 0 && $childRowLanguage === -1)
                    ) {
                        $childUid = (int)$inlineChildRow['uid'];
                        $tableNames[$childTableName] = true;
                        if ($childRowLanguage === -1) {
                            $updateFindings[$childTableName][] = new Finding(
                                new RecordKey($childTableName, $childUid),
                                (int)$inlineChildRow['pid'],
                                [
                                    new RecordChange(RecordChangeType::Update, $childTableName, $childUid, [
                                        $childTableLanguageField => [
                                            'value' => $parentRowLanguage,
                                            'type' => Connection::PARAM_INT,
                                        ],
                                    ]),
                                ],
                                new Reason('all-languages-parent-language', 'Language -1 with parent record language ' . $parentRowLanguage, ['parentLanguage' => $parentRowLanguage]),
                            );
                        } else {
                            $deleteFindings[$childTableName][] = new Finding(
                                new RecordKey($childTableName, $childUid),
                                (int)$inlineChildRow['pid'],
                                [$this->softOrHardDeleteChange($childTableName, $inlineChildRow)],
                                new Reason('parent-language-different', 'Parent record language ' . $parentRowLanguage, ['parentLanguage' => $parentRowLanguage]),
                            );
                        }
                    }
                } catch (NoSuchRecordException $e) {
                    // Record existence has been checked by InlineForeignFieldChildrenParentMissing already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
            }
        }
        return $this->mergeFindings($tableNames, $updateFindings, $deleteFindings);
    }

    protected function getDetailDbFields(string $tableName): array
    {
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            if ($inlineChild['tableName'] === $tableName) {
                return [
                    $inlineChild['fieldNameOfParentTableName'],
                    $inlineChild['fieldNameOfParentTableUid'],
                ];
            }
        }
        return [];
    }

    /**
     * Findings of all tables, table by table in the order they were found first: All
     * updates of a table before its deletes.
     *
     * @param array<string, true> $tableNames
     * @param array<string, array<int, Finding>> $updateFindings
     * @param array<string, array<int, Finding>> $deleteFindings
     * @return list<Finding>
     */
    private function mergeFindings(array $tableNames, array $updateFindings, array $deleteFindings): array
    {
        $findings = [];
        foreach (array_keys($tableNames) as $tableName) {
            array_push($findings, ...array_values($updateFindings[$tableName] ?? []), ...array_values($deleteFindings[$tableName] ?? []));
        }
        return $findings;
    }
}
