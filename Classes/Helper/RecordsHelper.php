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
use Lolli\Dbdoctor\Database\PreparedStatement;
use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Exception\UnexpectedNumberOfAffectedRowsException;
use Lolli\Dbdoctor\Finding\FieldValue;
use Lolli\Dbdoctor\Finding\FieldValueList;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stateless: Prepared statements are kept in PreparedStatements of the calling run.
 */
final readonly class RecordsHelper
{
    public function __construct(
        private TableHelper $tableHelper,
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
            if (!$this->tableHelper->tableExistsInDatabase($tableName)) {
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
        $sqlString = $this->renderSql($tableName, $preparedStatement->sqlString, [new FieldValue($uid, Connection::PARAM_INT)]);
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
     * @param array<string, FieldValue> $fields
     */
    public function updateTcaRecord(PreparedStatements $statements, bool $simulate, string $tableName, int $uid, array $fields): string
    {
        $statementKey = 'update-' . $tableName . '-' . implode(',', array_keys($fields));
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->update($tableName);
            foreach (array_keys($fields) as $fieldName) {
                $queryBuilder->set($fieldName, '?', false);
            }
            $queryBuilder->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createPositionalParameter(0, Connection::PARAM_INT))
            );
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $parameters = [...array_values($fields), new FieldValue($uid, Connection::PARAM_INT)];
        $sqlString = $this->renderSql($tableName, $preparedStatement->sqlString, $parameters);
        if (!$simulate) {
            $this->bindParameters($statement, $parameters);
            $affectedRows = $statement->executeStatement();
            if ($affectedRows !== 1) {
                throw new UnexpectedNumberOfAffectedRowsException(
                    'Update query "' . $sqlString . '" had "' . $affectedRows . '" affected rows, 1 expected.',
                    1646228188
                );
            }
        }
        return $sqlString;
    }

    /**
     * @param array<string, FieldValue> $fields
     */
    public function insertTcaRecord(PreparedStatements $statements, bool $simulate, string $tableName, array $fields): string
    {
        $statementKey = 'insert-' . $tableName . '-' . implode(',', array_keys($fields));
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->insert($tableName);
            foreach (array_keys($fields) as $fieldName) {
                $queryBuilder->setValue($fieldName, '?', false);
            }
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $parameters = array_values($fields);
        $sqlString = $this->renderSql($tableName, $preparedStatement->sqlString, $parameters);
        if (!$simulate) {
            $this->bindParameters($statement, $parameters);
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
     * DELETE rows of an MM table. MM tables are no TCA tables, they have a uid
     * column only for fields with "multiple": Rows are identified by all given
     * fields. There may be duplicate rows, so at least one affected row is expected.
     * A field with a FieldValueList is restricted by IN(), the type is the type
     * of each value. Callers keep the list short: Platforms limit the number of
     * bound parameters of a statement.
     *
     * @param array<string, FieldValue|FieldValueList> $whereFields
     */
    public function deleteMmRows(PreparedStatements $statements, bool $simulate, string $mmTableName, array $whereFields): string
    {
        if (empty($whereFields)) {
            throw new \RuntimeException('Must restrict MM rows to delete by at least one field.', 1791484210);
        }
        $statementKeyParts = [];
        foreach ($whereFields as $fieldName => $whereField) {
            $statementKeyParts[] = $fieldName . ($whereField instanceof FieldValueList ? ':' . count($whereField->values) : '');
        }
        $statementKey = 'deleteMm-' . $mmTableName . '-' . implode(',', $statementKeyParts);
        $preparedStatement = $statements->get($statementKey);
        if ($preparedStatement === null) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTableName);
            $queryBuilder->delete($mmTableName);
            foreach ($whereFields as $fieldName => $whereField) {
                if ($whereField instanceof FieldValueList) {
                    $queryBuilder->andWhere($queryBuilder->expr()->in($fieldName, array_fill(0, count($whereField->values), '?')));
                } else {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($fieldName, '?'));
                }
            }
            $preparedStatement = $statements->add($statementKey, new PreparedStatement($queryBuilder->prepare(), $queryBuilder->getSQL()));
        }
        $statement = $preparedStatement->statement;
        $parameters = [];
        foreach ($whereFields as $whereField) {
            if ($whereField instanceof FieldValueList) {
                foreach ($whereField->values as $value) {
                    $parameters[] = new FieldValue($value, $whereField->type);
                }
                continue;
            }
            $parameters[] = $whereField;
        }
        $sqlString = $this->renderSql($mmTableName, $preparedStatement->sqlString, $parameters);
        if (!$simulate) {
            $this->bindParameters($statement, $parameters);
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

    /**
     * SQL of a prepared statement with its "?" placeholders replaced by the quoted values, for output
     * and the dump file. The prepared SQL has values only as placeholders, so each "?" is one. The SQL
     * is split at the placeholders first: A value is never searched again, even if it contains "?".
     *
     * @param list<FieldValue> $parameters
     */
    private function renderSql(string $tableName, string $preparedSql, array $parameters): string
    {
        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $sqlValues = [];
        foreach ($parameters as $parameter) {
            if ($parameter->type === Connection::PARAM_STR || $parameter->type === Connection::PARAM_LOB) {
                $sqlValues[] = $connection->quote((string)$parameter->value);
            } else {
                $sqlValues[] = (string)$parameter->value;
            }
        }
        $sqlParts = explode('?', $preparedSql);
        $sqlString = array_shift($sqlParts);
        foreach ($sqlParts as $index => $sqlPart) {
            $sqlString .= $sqlValues[$index] . $sqlPart;
        }
        return $sqlString . ';';
    }

    /**
     * @param list<FieldValue> $parameters
     */
    private function bindParameters(Statement $statement, array $parameters): void
    {
        foreach ($parameters as $index => $parameter) {
            $statement->bindValue($index + 1, $parameter->value, $parameter->type);
        }
    }
}
