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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Inline foreign field without TCA foreign_table_field children must have existing parent record.
 */
final readonly class InlineForeignFieldNoForeignTableFieldChildrenParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with missing parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'TCA inline foreign field records point to a parent record. This parent must exist.',
            'This check is for inline children defined *without* foreign_table_field in TCA.',
            'Inline children with missing parent are deleted.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $affectedRows = [];

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
                    $inlineChildRow['_reasonBroken'] = 'Missing parent';
                    $inlineChildRow['_parentTableName'] = $parentTableName;
                    $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                    // Keyed by uid: Two parent fields of the same parent table may select the same row.
                    $affectedRows[$childTableName][(int)$inlineChildRow['uid']] = $inlineChildRow;
                }
            }
        }
        foreach ($affectedRows as $childTableName => $rows) {
            ksort($rows);
            $affectedRows[$childTableName] = array_values($rows);
        }
        return $affectedRows;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecords($run, $simulate, $affectedRecords);
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldNameOfParentTableUid'],
            ];
            $this->outputRecordDetails($run, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
