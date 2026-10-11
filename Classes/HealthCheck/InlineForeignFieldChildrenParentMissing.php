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

/**
 * Inline foreign field children must have existing parent record.
 */
final readonly class InlineForeignFieldChildrenParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for inline foreign field records with missing parent',
            [self::TAG_REMOVE],
            [
                'TCA inline foreign field records point to a parent record. This parent must exist.',
                'This check is for inline children defined *with* foreign_table_field in TCA.',
                'Inline children with missing parent are deleted.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $fieldNameOfParentTableName = $inlineChild['fieldNameOfParentTableName'];
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Consider deleted records: If the parent does not exist, they should be deleted, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $fieldNameOfParentTableName, $fieldNameOfParentTableUid)
                ->from($childTableName)
                ->where(
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
                    $findings[] = new Finding(
                        new RecordKey($childTableName, (int)$inlineChildRow['uid']),
                        (int)$inlineChildRow['pid'],
                        [new RecordChange(RecordChangeType::Delete, $childTableName, (int)$inlineChildRow['uid'])],
                        new Reason('parent-invalid', 'Invalid parent'),
                    );
                    continue;
                }
                try {
                    $this->recordsHelper->getRecord($run->statements, (string)$inlineChildRow[$fieldNameOfParentTableName], ['uid'], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                } catch (NoSuchRecordException $e) {
                    $findings[] = new Finding(
                        new RecordKey($childTableName, (int)$inlineChildRow['uid']),
                        (int)$inlineChildRow['pid'],
                        [new RecordChange(RecordChangeType::Delete, $childTableName, (int)$inlineChildRow['uid'])],
                        new Reason('parent-missing', 'Missing parent'),
                    );
                }
            }
        }
        return $findings;
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
}
