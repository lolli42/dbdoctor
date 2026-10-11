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
 * Inline foreign field without TCA foreign_table_field children must have existing parent record.
 */
final readonly class InlineForeignFieldNoForeignTableFieldChildrenParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline foreign field records with missing parent',
            [self::TAG_REMOVE],
            [
                'TCA inline foreign field records point to a parent record. This parent must exist.',
                'This check is for inline children defined *without* foreign_table_field in TCA.',
                'Inline children with missing parent are deleted.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findingsByTable = [];

        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $parentTableName = $inlineChild['parentTableName'];
            if (!$this->tableHelper->tableExistsInDatabase($parentTableName)) {
                continue;
            }
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Consider deleted records: If the parent does not exist, they should be deleted, too.
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $fieldNameOfParentTableUid)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->gt($fieldNameOfParentTableUid, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid');
            foreach ($inlineChild['matchFields'] as $matchFieldName => $matchFieldValue) {
                // Child table may have multiple parent tables, consider only rows of this parent.
                $parameter = $this->tableHelper->fieldIsInteger($childTableName, $matchFieldName)
                    ? $queryBuilder->createNamedParameter((int)$matchFieldValue, Connection::PARAM_INT)
                    : $queryBuilder->createNamedParameter((string)$matchFieldValue);
                $queryBuilder->andWhere($queryBuilder->expr()->eq($matchFieldName, $parameter));
            }
            $result = $queryBuilder->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                try {
                    $this->recordsHelper->getRecord($run->statements, $parentTableName, ['uid'], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                } catch (NoSuchRecordException $e) {
                    // Keyed by uid: Two parent fields of the same parent table may select the same row.
                    $findingsByTable[$childTableName][(int)$inlineChildRow['uid']] = new Finding(
                        new RecordKey($childTableName, (int)$inlineChildRow['uid']),
                        (int)$inlineChildRow['pid'],
                        [new RecordChange(RecordChangeType::Delete, $childTableName, (int)$inlineChildRow['uid'])],
                        new Reason('parent-missing', 'Missing parent'),
                    );
                }
            }
        }
        $findings = [];
        foreach ($findingsByTable as $tableFindings) {
            ksort($tableFindings);
            $findings = array_merge($findings, array_values($tableFindings));
        }
        return $findings;
    }

    protected function getDetailDbFields(string $tableName): array
    {
        $fields = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldParent() as $inlineChild) {
            if ($inlineChild['tableName'] === $tableName
                && !in_array($inlineChild['fieldNameOfParentTableUid'], $fields, true)
            ) {
                $fields[] = $inlineChild['fieldNameOfParentTableUid'];
            }
        }
        return $fields;
    }
}
