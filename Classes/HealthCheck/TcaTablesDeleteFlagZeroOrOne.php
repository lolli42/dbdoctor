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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Values of delete column of TCA tables with enabled soft-delete must be either 0 or 1.
 */
final class TcaTablesDeleteFlagZeroOrOne extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for rows with delete field not "0" or "1"');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE);
        $io->text([
            'Values of the "deleted" column of TCA tables with enabled soft-delete',
            '(["ctrl"]["delete"] set to a column name) must be either zero (0) or one (1).',
            'The default core database DeletedRestriction tests for equality with zero.',
            'This scan finds records having a different value than zero or one and sets them to one.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextSoftDeleteAwareTable() as $tableName) {
            $tableDeleteField = $this->tcaHelper->getDeletedField($tableName);
            if (empty($tableDeleteField)) {
                throw new \RuntimeException(
                    'The delete field must not be empty. This exception indicates a bug in getNextSoftDeleteAwareTable()',
                    1688205435
                );
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid', $tableDeleteField)->from($tableName)->orderBy('uid');
            $queryBuilder->where(
                $queryBuilder->expr()->neq($tableDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq($tableDeleteField, $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            );
            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRows[$tableName][(int)$row['uid']] = $row;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            // Force "deleted=1" for affected rows.
            $deletedField = $this->tcaHelper->getDeletedField($tableName);
            $updateFields = [
                $deletedField => [
                    'value' => 1,
                    'type' => Connection::PARAM_INT,
                ],
            ];
            $this->updateTcaRecordsOfTable($io, $simulate, $tableName, $tableRows, $updateFields);
        }
    }
}
