<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\DatabaseSchema;

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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeCategory;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeExecutor;
use Lolli\Dbdoctor\DatabaseSchema\SchemaConflict;
use Lolli\Dbdoctor\DatabaseSchema\SchemaConflictDetector;
use Lolli\Dbdoctor\DatabaseSchema\SchemaConflictType;
use Lolli\Dbdoctor\DatabaseSchema\SchemaInspector;
use Lolli\Dbdoctor\DatabaseSchema\SchemaLevel;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

final class SchemaConflictDetectorTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        // The test instance is shared with other test classes: Leave the schema as it was.
        $this->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_defined');
        parent::tearDown();
    }

    private function addTableDefinition(string $sql): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'schema-conflict-detector-test',
            static function (AlterTableDefinitionStatementsEvent $event) use ($sql): void {
                $event->addSqlData($sql);
            }
        );
        $this->get(ListenerProvider::class)->addListener(AlterTableDefinitionStatementsEvent::class, 'schema-conflict-detector-test');
    }

    private function executeStatement(string $sql): void
    {
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->executeStatement($sql);
    }

    /**
     * Create the table as defined, as the core would, then insert rows.
     */
    private function createDefinedTable(string $definition, string $insertValues): void
    {
        $this->addTableDefinition($definition);
        $this->get(SchemaChangeExecutor::class)->execute($this->get(SchemaInspector::class)->getChanges(), '');
        $this->executeStatement('INSERT INTO tx_dbdoctortest_defined (uid, title) VALUES ' . $insertValues);
    }

    private function isSqlite(): bool
    {
        return $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->getDatabasePlatform() instanceof SQLitePlatform;
    }

    #[Test]
    public function getConflictsReturnsNothingOnCleanDatabase(): void
    {
        self::assertSame([], $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::All));
    }

    #[Test]
    public function getConflictsReturnsNullValuesOfColumnThatBecomesNotNull(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT NULL);',
            '(1, NULL), (2, NULL), (3, \'a\')'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $expected = [
            new SchemaConflict(SchemaConflictType::NullValues, SchemaChangeCategory::Changed, 'Default', 'tx_dbdoctortest_defined', ['title'], 2),
        ];
        self::assertEquals($expected, $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Recommended));
    }

    #[Test]
    public function getConflictsWithLevelRequiredIgnoresChangedColumns(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT NULL);',
            '(1, NULL)'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        self::assertSame([], $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Required));
    }

    #[Test]
    public function getConflictsReturnsNotNumericValuesOfTextColumnThatBecomesInteger(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(60) DEFAULT \'\' NOT NULL);',
            '(1, \'\'), (2, \'abc\'), (3, \'5\'), (4, \'-3\'), (5, \'\'), (6, \'1234567890123456789012345678901234567890123456789\')'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title INT(11) DEFAULT 0 NOT NULL);');
        // sqlite does not enforce types
        $expected = $this->isSqlite()
            ? []
            : [new SchemaConflict(SchemaConflictType::NotNumericValues, SchemaChangeCategory::Changed, 'Default', 'tx_dbdoctortest_defined', ['title'], 4, 'integer')];
        self::assertEquals($expected, $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Recommended));
    }

    #[Test]
    public function getConflictsReturnsTooLongValuesOfColumnThatBecomesShorter(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(20) DEFAULT \'\' NOT NULL);',
            '(1, \'short\'), (2, \'exactly 10\'), (3, \'eleven char\')'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        // sqlite does not enforce lengths
        $expected = $this->isSqlite()
            ? []
            : [new SchemaConflict(SchemaConflictType::TooLongValues, SchemaChangeCategory::Changed, 'Default', 'tx_dbdoctortest_defined', ['title'], 1, '10')];
        self::assertEquals($expected, $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Recommended));
    }

    #[Test]
    public function getConflictsReturnsNegativeValuesOfColumnThatBecomesUnsigned(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title INT(11) DEFAULT 0 NOT NULL);',
            '(1, -1), (2, 0), (3, 1)'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title INT(11) UNSIGNED DEFAULT 0 NOT NULL);');
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        // Only mysql and mariadb enforce unsigned columns
        $expected = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? [new SchemaConflict(SchemaConflictType::NegativeValues, SchemaChangeCategory::Changed, 'Default', 'tx_dbdoctortest_defined', ['title'], 1)]
            : [];
        self::assertEquals($expected, $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Recommended));
    }

    #[Test]
    public function getConflictsReturnsDuplicateValuesOfAddedUniqueIndex(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);',
            '(1, \'same\'), (2, \'same\'), (3, \'other\')'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (title));');
        $conflicts = $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::Required);
        self::assertCount(1, $conflicts);
        self::assertSame(SchemaConflictType::DuplicateValues, $conflicts[0]->type);
        self::assertSame(SchemaChangeCategory::Missing, $conflicts[0]->category);
        self::assertSame('tx_dbdoctortest_defined', $conflicts[0]->tableName);
        self::assertSame(['title'], $conflicts[0]->columnNames);
        self::assertSame(2, $conflicts[0]->rowCount);
        // sqlite adds a hash to index names
        self::assertStringStartsWith('tx_dbdoctortest_unique', $conflicts[0]->expected);
    }

    #[Test]
    public function getConflictsIgnoresUniqueIndexOfAddedColumn(): void
    {
        $this->createDefinedTable(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);',
            '(1, \'same\'), (2, \'same\')'
        );
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, added VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (added));');
        self::assertSame([], $this->get(SchemaConflictDetector::class)->getConflicts(SchemaLevel::All));
    }
}
