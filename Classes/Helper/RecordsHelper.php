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
use Doctrine\DBAL\ParameterType;
use Lolli\Dbdoctor\Database\PreparedStatement;
use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Exception\UnexpectedNumberOfAffectedRowsException;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stateless: Prepared statements are kept in PreparedStatements of the calling run.
 */
final readonly class RecordsHelper
{
    public function __construct(
        private ContainerInterface $container,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<int, string> $fields
     * @return array<string, int|string>
     * @throws NoSuchRecordException
     * @throws NoSuchTableException
     */
    public function getRecord(PreparedStatements $statements, string $tableName, array $fields, int $uid): array
    {
        if (empty($fields)) {
            throw new \RuntimeException('Must select at least one field. Maybe uid?', 1647791187);
        }
        $statementKey = 'select-' . $tableName . '-' . implode(',', $fields);
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
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
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, Connection::PARAM_INT))
                );
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare()));
        }
        $statement = $preparedStatement->statement;
        $statement->bindValue(1, $uid, Connection::PARAM_INT);
        $result = $statement->executeQuery();
        $record = $result->fetchAllAssociative();
        $result->free();
        $record = array_pop($record);
        if (!is_array($record)) {
            throw new NoSuchRecordException('Record with uid "' . $uid . '" in table "' . $tableName . '" not found', 1646121410);
        }
        return $record;
    }

    public function deleteTcaRecord(PreparedStatements $statements, bool $simulate, string $tableName, int $uid): string
    {
        $statementKey = 'delete-' . $tableName;
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->delete($tableName)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, Connection::PARAM_INT))
                );
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $sqlString = $preparedStatement->sqlString;
        $sqlString = str_replace('= ?', '= ' . $uid, $sqlString);
        $sqlString .= ';';
        if (!$simulate) {
            $statement->bindValue(1, $uid, Connection::PARAM_INT);
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
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    public function updateTcaRecord(PreparedStatements $statements, bool $simulate, string $tableName, int $uid, array $fields): string
    {
        $statementKey = 'update-' . $tableName . '-' . implode(',', array_keys($fields));
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->update($tableName);
            foreach ($fields as $fieldName => $valueAndType) {
                $queryBuilder->set($fieldName, '?', false);
            }
            $queryBuilder->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, Connection::PARAM_INT))
            );
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $sqlString = $preparedStatement->sqlString;
        $currentParam = 1;
        foreach ($fields as $valueAndType) {
            if ($valueAndType['type'] === Connection::PARAM_STR) {
                $sqlValue = '\'' . $valueAndType['value'] . '\'';
            } else {
                $sqlValue = (string)$valueAndType['value'];
            }
            $sqlString = $this->strReplaceFirst('= ?', '= ' . $sqlValue, $sqlString);
            if (!$simulate) {
                $statement->bindValue($currentParam, $valueAndType['value'], $valueAndType['type']);
            }
            $currentParam++;
        }
        $sqlString = $this->strReplaceFirst('= ?', '= ' . $uid, $sqlString);
        $sqlString .= ';';
        if (!$simulate) {
            $statement->bindValue($currentParam, $uid, Connection::PARAM_INT);
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

    /**
     * @param array<string, array{value: int|string, type: ParameterType}> $fields
     */
    public function insertTcaRecord(PreparedStatements $statements, bool $simulate, string $tableName, array $fields): string
    {
        $statementKey = 'insert-' . $tableName . '-' . implode(',', array_keys($fields));
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->insert($tableName);
            foreach ($fields as $fieldName => $valueAndType) {
                $queryBuilder->setValue($fieldName, '?', false);
            }
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $sqlString = $preparedStatement->sqlString;
        $currentParam = 1;
        foreach ($fields as $valueAndType) {
            if ($valueAndType['type'] === Connection::PARAM_STR || $valueAndType['type'] === Connection::PARAM_LOB) {
                $sqlValue = '\'' . $valueAndType['value'] . '\'';
            } else {
                $sqlValue = (string)$valueAndType['value'];
            }
            $sqlString = $this->strReplaceFirst('?', $sqlValue, $sqlString);
            if (!$simulate) {
                $statement->bindValue($currentParam, $valueAndType['value'], $valueAndType['type']);
            }
            $currentParam++;
        }
        $sqlString .= ';';
        if (!$simulate) {
            $affectedRows = $statement->executeStatement();
            if ($affectedRows !== 1) {
                throw new UnexpectedNumberOfAffectedRowsException(
                    'Insert query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, 1 expected.',
                    1791476400
                );
            }
        }
        return $sqlString;
    }

    /**
     * DELETE rows of an MM table. MM tables are no TCA tables and have no uid
     * column: Rows are identified by all given fields. There may be duplicate
     * rows, so at least one affected row is expected.
     *
     * @param array<string, array{value: int|string, type: ParameterType}> $whereFields
     */
    public function deleteMmRows(PreparedStatements $statements, bool $simulate, string $mmTableName, array $whereFields): string
    {
        if (empty($whereFields)) {
            throw new \RuntimeException('Must restrict MM rows to delete by at least one field.', 1791484210);
        }
        $statementKey = 'deleteMm-' . $mmTableName . '-' . implode(',', array_keys($whereFields));
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTableName);
            $queryBuilder->delete($mmTableName);
            foreach ($whereFields as $fieldName => $valueAndType) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($fieldName, '?'));
            }
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $sqlString = $preparedStatement->sqlString;
        $currentParam = 1;
        foreach ($whereFields as $valueAndType) {
            if ($valueAndType['type'] === Connection::PARAM_STR) {
                $sqlValue = '\'' . $valueAndType['value'] . '\'';
            } else {
                $sqlValue = (string)$valueAndType['value'];
            }
            $sqlString = $this->strReplaceFirst('= ?', '= ' . $sqlValue, $sqlString);
            if (!$simulate) {
                $statement->bindValue($currentParam, $valueAndType['value'], $valueAndType['type']);
            }
            $currentParam++;
        }
        $sqlString .= ';';
        if (!$simulate) {
            $affectedRows = $statement->executeStatement();
            if ($affectedRows < 1) {
                throw new UnexpectedNumberOfAffectedRowsException(
                    'Delete query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, at least 1 expected.',
                    1791484211
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
