<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

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
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Exception\UnexpectedNumberOfAffectedRowsException;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

class RecordsHelper
{
    /**
     * @var array<string, array{string, sqlString: string, statement: Statement}>
     */
    private array $preparedStatements = [];

    private ContainerInterface $container;
    private ConnectionPool $connectionPool;

    public function __construct(
        ContainerInterface $container,
        ConnectionPool $connectionPool
    ) {
        $this->container = $container;
        $this->connectionPool = $connectionPool;
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, int|string>
     * @throws NoSuchRecordException
     * @throws NoSuchTableException
     */
    public function getRecord(string $tableName, array $fields, int $uid): array
    {
        $statementHash = md5('select' . $tableName . implode($fields));
        if (!isset($this->preparedStatements[$statementHash])) {
            /** @var TableHelper $tableHelper */
            $tableHelper = $this->container->get(TableHelper::class);
            if (!$tableHelper->tableExistsInDatabase($tableName)) {
                throw new NoSuchTableException('Table "' . $tableName . '" does not exist.');
            }
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
        $statement->bindParam(1, $uid, \PDO::PARAM_INT);
        $result = $statement->executeQuery();
        $record = $result->fetchAllAssociative();
        $result->free();
        /** @var array<string, int|string> $record */
        $record = array_pop($record);
        if (!is_array($record)) {
            throw new NoSuchRecordException('Record with uid "' . $uid . '" in table "' . $tableName . '" not found', 1646121410);
        }
        return $record;
    }

    public function deleteTcaRecord(bool $simulate, string $tableName, int $uid): string
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
        $statement = $this->preparedStatements[$statementHash]['statement'];
        $sqlString = $this->preparedStatements[$statementHash]['sqlString'];
        $sqlString = str_replace('= ?', '= ' . $uid, $sqlString);
        $sqlString .= ';';
        if (!$simulate) {
            $statement->bindParam(1, $uid, \PDO::PARAM_INT);
            $affectedRows = $statement->executeStatement();
            if ($affectedRows !== 1) {
                throw new UnexpectedNumberOfAffectedRowsException(
                    'Delete query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, 1 expected.',
                    1646137196
                );
            }
        }
        return $sqlString;
    }

    /**
     * @param array<string, array<string, int|string>> $fields
     */
    public function updateTcaRecord(bool $simulate, string $tableName, int $uid, array $fields): string
    {
        $statementHash = md5('update' . $tableName . implode('', array_keys($fields)));
        if (!isset($this->preparedStatements[$statementHash])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->update($tableName);
            foreach ($fields as $fieldName => $valueAndType) {
                $queryBuilder->set($fieldName, '?', false);
            }
            $queryBuilder->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, \PDO::PARAM_INT))
            );
            $this->preparedStatements[$statementHash]['sqlString'] = $queryBuilder->getSQL();
            $this->preparedStatements[$statementHash]['statement'] = $queryBuilder->prepare();
        }
        /** @var Statement $statement */
        $statement = $this->preparedStatements[$statementHash]['statement'];
        $sqlString = $this->preparedStatements[$statementHash]['sqlString'];
        $currentParam = 1;
        foreach ($fields as $valueAndType) {
            if ($valueAndType['type'] === \PDO::PARAM_STR) {
                $sqlValue = '\'' . $valueAndType['value'] . '\'';
            } else {
                $sqlValue = (string)$valueAndType['value'];
            }
            $sqlString = $this->strReplaceFirst('= ?', '= ' . $sqlValue, $sqlString);
            if (!$simulate) {
                $statement->bindParam($currentParam, $valueAndType['value'], (int)$valueAndType['type']);
            }
            $currentParam++;
        }
        $sqlString = $this->strReplaceFirst('= ?', '= ' . $uid, $sqlString);
        $sqlString .= ';';
        if (!$simulate) {
            $statement->bindParam($currentParam, $uid, \PDO::PARAM_INT);
            $affectedRows = $statement->executeStatement();
            if ($affectedRows !== 1) {
                throw new UnexpectedNumberOfAffectedRowsException(
                    'Delete query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, 1 expected.',
                    1646228188
                );
            }
        }
        return $sqlString;
    }

    private function strReplaceFirst(string $search, string $replace, string $subject): string
    {
        $search = '/' . preg_quote($search, '/') . '/';
        return (string)preg_replace($search, $replace, $subject, 1);
    }
}
