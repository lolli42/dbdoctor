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

use Lolli\Dbdoctor\DatabaseSchema\SchemaChange;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeCategory;
use Lolli\Dbdoctor\DatabaseSchema\SchemaInspector;
use Lolli\Dbdoctor\DatabaseSchema\SchemaLevel;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

final class SchemaInspectorTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        // The test instance is shared with other test classes: Leave the schema as it was.
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_defined');
        $connection->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_undefined');
        $connection->executeStatement('DROP TABLE IF EXISTS zzz_deleted_tx_dbdoctortest');
        parent::tearDown();
    }

    private function addTableDefinition(string $sql): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'schema-inspector-test',
            static function (AlterTableDefinitionStatementsEvent $event) use ($sql): void {
                $event->addSqlData($sql);
            }
        );
        $this->get(ListenerProvider::class)->addListener(AlterTableDefinitionStatementsEvent::class, 'schema-inspector-test');
    }

    private function executeStatement(string $sql): void
    {
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->executeStatement($sql);
    }

    /**
     * Create a table defined by addTableDefinition() as the core would.
     */
    private function createDefinedTable(): void
    {
        foreach ($this->get(SchemaInspector::class)->getChanges() as $change) {
            if ($change->category === SchemaChangeCategory::Missing) {
                $this->executeStatement($change->statement);
            }
        }
    }

    /**
     * All changes are of the given category. sqlite needs several statements for one change of a
     * table, it re-creates the table: Return the last change that contains the given part.
     *
     * @param list<SchemaChange> $changes
     */
    private function assertChangesOfCategory(array $changes, SchemaChangeCategory $category, string $statementPart): SchemaChange
    {
        $found = null;
        foreach ($changes as $change) {
            self::assertSame($category, $change->category, $change->statement);
            self::assertSame(ConnectionPool::DEFAULT_CONNECTION_NAME, $change->connectionName);
            self::assertSame(md5($change->statement), $change->hash);
            if (str_contains($change->statement, $statementPart)) {
                $found = $change;
            }
        }
        self::assertNotNull($found, 'No statement contains "' . $statementPart . '"');
        return $found;
    }

    #[Test]
    public function getChangesReturnsNothingOnCleanDatabase(): void
    {
        self::assertSame([], $this->get(SchemaInspector::class)->getChanges());
    }

    #[Test]
    public function getChangesReturnsMissingTable(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $change = $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Missing, 'tx_dbdoctortest_defined');
        self::assertNull($change->currentDefinition);
        self::assertNull($change->rowCount);
    }

    #[Test]
    public function getChangesReturnsMissingColumn(): void
    {
        $this->addTableDefinition('CREATE TABLE pages (tx_dbdoctortest_field VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Missing, 'tx_dbdoctortest_field');
    }

    #[Test]
    public function getChangesReturnsMissingIndex(): void
    {
        $this->addTableDefinition('CREATE TABLE pages (KEY tx_dbdoctortest_index (doktype));');
        $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Missing, 'tx_dbdoctortest_index');
    }

    #[Test]
    public function getChangesReturnsChangedColumnWithCurrentDefinition(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->createDefinedTable();
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(20) DEFAULT \'\' NOT NULL);');
        $change = $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Changed, 'VARCHAR(20)');
        self::assertStringContainsString('title', (string)$change->currentDefinition);
        self::assertStringContainsString('10', (string)$change->currentDefinition);
    }

    #[Test]
    public function getChangesReturnsUnusedTableWithRowCount(): void
    {
        $this->executeStatement('CREATE TABLE tx_dbdoctortest_undefined (uid INT)');
        $this->executeStatement('INSERT INTO tx_dbdoctortest_undefined (uid) VALUES (1), (2)');
        $change = $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Unused, 'zzz_deleted_tx_dbdoctortest_undefined');
        self::assertSame(2, $change->rowCount);
    }

    #[Test]
    public function getChangesReturnsUnusedColumn(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $this->createDefinedTable();
        $this->executeStatement('ALTER TABLE tx_dbdoctortest_defined ADD extra INT');
        $change = $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Unused, 'zzz_deleted_extra');
        self::assertNull($change->rowCount);
    }

    #[Test]
    public function getChangesReturnsObsoleteTableWithRowCount(): void
    {
        $this->executeStatement('CREATE TABLE zzz_deleted_tx_dbdoctortest (uid INT)');
        $this->executeStatement('INSERT INTO zzz_deleted_tx_dbdoctortest (uid) VALUES (1)');
        $change = $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Obsolete, 'zzz_deleted_tx_dbdoctortest');
        self::assertSame(1, $change->rowCount);
    }

    #[Test]
    public function getChangesReturnsObsoleteColumn(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $this->createDefinedTable();
        $this->executeStatement('ALTER TABLE tx_dbdoctortest_defined ADD zzz_deleted_extra INT');
        $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(), SchemaChangeCategory::Obsolete, 'tx_dbdoctortest_defined');
    }

    #[Test]
    public function getChangesIgnoresNumberDefaultsOfStringAndFloatColumns(): void
    {
        $this->addTableDefinition(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, price double DEFAULT 0 NOT NULL,'
            . ' rate float DEFAULT -1.5 NOT NULL, amount decimal(10,2) DEFAULT 0 NOT NULL, code varchar(6) DEFAULT 0 NOT NULL);'
        );
        // Create the table with the definition as is, as the core does
        $sqlReader = $this->get(SqlReader::class);
        $this->get(SchemaMigrator::class)->install($sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString()), true);
        self::assertSame([], $this->get(SchemaInspector::class)->getChanges());
    }

    #[Test]
    public function getChangesReturnsChangesOrderedByCategory(): void
    {
        $this->executeStatement('CREATE TABLE zzz_deleted_tx_dbdoctortest (uid INT)');
        $this->executeStatement('CREATE TABLE tx_dbdoctortest_undefined (uid INT)');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $categories = array_map(
            static fn(SchemaChange $change): SchemaChangeCategory => $change->category,
            $this->get(SchemaInspector::class)->getChanges()
        );
        self::assertSame([SchemaChangeCategory::Missing, SchemaChangeCategory::Unused, SchemaChangeCategory::Obsolete], $categories);
    }

    #[Test]
    public function getChangesWithLevelRequiredReturnsMissingOnly(): void
    {
        $this->executeStatement('CREATE TABLE tx_dbdoctortest_undefined (uid INT)');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->createDefinedTable();
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(20) DEFAULT \'\' NOT NULL);' . "\n" . 'CREATE TABLE pages (tx_dbdoctortest_field VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->assertChangesOfCategory($this->get(SchemaInspector::class)->getChanges(SchemaLevel::Required), SchemaChangeCategory::Missing, 'tx_dbdoctortest_field');
    }

    #[Test]
    public function getChangesWithLevelRecommendedReturnsMissingAndChanged(): void
    {
        $this->executeStatement('CREATE TABLE tx_dbdoctortest_undefined (uid INT)');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->createDefinedTable();
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(20) DEFAULT \'\' NOT NULL);' . "\n" . 'CREATE TABLE pages (tx_dbdoctortest_field VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $categories = array_values(array_unique(array_map(
            static fn(SchemaChange $change): string => $change->category->value,
            $this->get(SchemaInspector::class)->getChanges(SchemaLevel::Recommended)
        )));
        self::assertSame([SchemaChangeCategory::Missing->value, SchemaChangeCategory::Changed->value], $categories);
    }

    #[Test]
    public function isInLevelReturnsMissingOnlyForRequired(): void
    {
        $subject = $this->get(SchemaInspector::class);
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Missing, SchemaLevel::Required));
        self::assertFalse($subject->isInLevel(SchemaChangeCategory::Changed, SchemaLevel::Required));
        self::assertFalse($subject->isInLevel(SchemaChangeCategory::Unused, SchemaLevel::Required));
        self::assertFalse($subject->isInLevel(SchemaChangeCategory::Obsolete, SchemaLevel::Required));
    }

    #[Test]
    public function isInLevelReturnsMissingAndChangedForRecommended(): void
    {
        $subject = $this->get(SchemaInspector::class);
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Missing, SchemaLevel::Recommended));
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Changed, SchemaLevel::Recommended));
        self::assertFalse($subject->isInLevel(SchemaChangeCategory::Unused, SchemaLevel::Recommended));
        self::assertFalse($subject->isInLevel(SchemaChangeCategory::Obsolete, SchemaLevel::Recommended));
    }

    #[Test]
    public function isInLevelReturnsAllForAll(): void
    {
        $subject = $this->get(SchemaInspector::class);
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Missing, SchemaLevel::All));
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Changed, SchemaLevel::All));
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Unused, SchemaLevel::All));
        self::assertTrue($subject->isInLevel(SchemaChangeCategory::Obsolete, SchemaLevel::All));
    }
}
