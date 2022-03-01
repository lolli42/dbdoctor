<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Helper;

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

use Doctrine\DBAL\Statement;
use Lolli\Dbhealth\Exception\NoSuchRecordException;
use Lolli\Dbhealth\Exception\UnexpectedNumberOfAffectedRowsException;
use TYPO3\CMS\Core\Database\ConnectionPool;

class RecordsHelper
{
    /**
     * @var array<string, array{string, sqlString: string, statement: Statement}>
     * @phpstan-ignore-next-line - docrine/dbal 2.13 triggers here with core v11
     */
    private array $preparedStatements = [];

    private ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, int|string>
     */
    public function getRecord(string $tableName, array $fields, int $uid): array
    {
        $statementHash = md5('select' . $tableName . implode($fields));
        if (!isset($this->preparedStatements[$statementHash])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->select(...$fields)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, \PDO::PARAM_INT))
                );
            $this->preparedStatements[$statementHash]['statement'] = $queryBuilder->prepare();
        }
        $statement = $this->preparedStatements[$statementHash]['statement'];
        $statement->bindParam(1, $uid);
        $result = $statement->executeQuery();
        $record = $result->fetchAllAssociative();
        $result->free();
        /** @var array<string, int|string> $record */
        $record = array_pop($record);
        if (!is_array($record)) {
            throw new NoSuchRecordException('record with uid "' . $uid . '" in table "' . $tableName . '" not found', 1646121410);
        }
        return $record;
    }

    public function deleteTcaRecord(string $tableName, int $uid): string
    {
        $statementHash = md5('delete' . $tableName);
        if (!isset($this->preparedStatements[$statementHash])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->delete($tableName)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, \PDO::PARAM_INT))
                );
            $this->preparedStatements[$statementHash]['sqlString'] = $queryBuilder->getSQL();
            $this->preparedStatements[$statementHash]['statement'] = $queryBuilder->prepare();
        }
        /** @var Statement $statement */
        // @phpstan-ignore-next-line - docrine/dbal 2.13 triggers here with core v11
        $statement = $this->preparedStatements[$statementHash]['statement'];
        $sqlString = $this->preparedStatements[$statementHash]['sqlString'];
        $sqlString = str_replace('= ?', '= ' . $uid, $sqlString);
        $sqlString .= ';';
        $statement->bindParam(1, $uid);
        $affectedRows = $statement->executeStatement();
        if ($affectedRows !== 1) {
            throw new UnexpectedNumberOfAffectedRowsException(
                'Delete query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, 1 expected.',
                1646137196
            );
        }
        return $sqlString;
    }
}
