<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Commands;

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

use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

final class SchemaCommandTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        // The test instance is shared with other test classes: Leave the schema as it was.
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_undefined');
        $connection->executeStatement('DROP TABLE IF EXISTS zzz_deleted_tx_dbdoctortest_undefined');
        $connection->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_defined');
        @unlink($this->instancePath . '/dbdoctor-file');
        parent::tearDown();
    }

    private function addTableDefinition(string $sql): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'schema-command-test',
            static function (AlterTableDefinitionStatementsEvent $event) use ($sql): void {
                $event->addSqlData($sql);
            }
        );
        $this->get(ListenerProvider::class)->addListener(AlterTableDefinitionStatementsEvent::class, 'schema-command-test');
    }

    private function createUndefinedTableWithTwoRows(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('CREATE TABLE tx_dbdoctortest_undefined (uid INT)');
        $connection->executeStatement('INSERT INTO tx_dbdoctortest_undefined (uid) VALUES (1), (2)');
    }

    private function tableExists(string $tableName): bool
    {
        return $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->createSchemaManager()->tablesExist([$tableName]);
    }

    /**
     * Display with whitespace collapsed: SymfonyStyle wraps long messages into blocks.
     */
    private function getNormalizedDisplay(CommandTester $commandTester): string
    {
        return (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay());
    }

    #[Test]
    public function reportsErrorForInvalidLevel(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--level' => 'some']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid level "some".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsUpToDateOnCleanDatabase(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        self::assertStringContainsString('Database schema is up to date with level "recommended".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsMissingTable(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('Missing tables, columns and indexes', $display);
        self::assertStringContainsString('tx_dbdoctortest_defined', $display);
        self::assertStringContainsString('Statements to update the database schema with level "recommended": 1.', $display);
    }

    #[Test]
    public function reportsMissingTableWithLevelRequired(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--level' => 'required']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertStringContainsString('tx_dbdoctortest_defined', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsChangedColumnWithCurrentDefinition(): void
    {
        $this->addTableDefinition('CREATE TABLE pages (title VARCHAR(20) DEFAULT \'\' NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('Changed columns, indexes and table options', $display);
        self::assertStringContainsString('VARCHAR(20)', $display);
        self::assertStringContainsString('Current: ', $display);
    }

    #[Test]
    public function hidesChangedColumnWithLevelRequired(): void
    {
        $this->addTableDefinition('CREATE TABLE pages (title VARCHAR(20) DEFAULT \'\' NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--level' => 'required']);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringNotContainsString('VARCHAR(20)', $display);
        self::assertStringContainsString('Statements not shown with level "required": ', $display);
        self::assertStringContainsString(' changed. Use "--level all" to show them.', $display);
        self::assertStringContainsString('Database schema is up to date with level "required".', $display);
    }

    #[Test]
    public function hidesUnusedTableWithLevelRecommended(): void
    {
        $this->createUndefinedTableWithTwoRows();
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringNotContainsString('tx_dbdoctortest_undefined', $display);
        self::assertStringContainsString('Statements not shown with level "recommended": 1 unused. Use "--level all" to show them.', $display);
    }

    #[Test]
    public function reportsUnusedTableWithRowCountWithLevelAll(): void
    {
        $this->createUndefinedTableWithTwoRows();
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--level' => 'all']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('Unused tables and columns, to rename', $display);
        self::assertStringContainsString('zzz_deleted_tx_dbdoctortest_undefined', $display);
        self::assertStringContainsString('Rows: 2', $display);
        self::assertStringNotContainsString('not shown', $display);
    }

    #[Test]
    public function reportsErrorForInvalidMode(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'fix']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid mode "fix".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForFileWithCheckMode(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--file' => $file]);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Option "--file" not available with "--mode check"', $this->getNormalizedDisplay($commandTester));
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function reportsErrorForExecuteModeWithoutFile(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Option "--file" with an absolute path to a file that does not exist yet is mandatory with "--mode execute".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForRelativeFile(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => 'dbdoctor-file']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid file "dbdoctor-file". Must be an absolute path.', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForExistingFile(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        file_put_contents($file, 'content');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('File exists already.', $this->getNormalizedDisplay($commandTester));
        self::assertSame('content', file_get_contents($file));
    }

    #[Test]
    public function reportsErrorForInteractiveModeWithoutTerminal(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'interactive'], ['interactive' => false]);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Mode "interactive" needs an interactive terminal', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function executeCreatesMissingTableAndLogsStatements(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertTrue($this->tableExists('tx_dbdoctortest_defined'));
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('Statements executed: 1.', $display);
        self::assertStringContainsString('Database schema is up to date with level "recommended".', $display);
        $fileContent = (string)file_get_contents($file);
        self::assertStringStartsWith('# dbdoctor:schema missing' . "\n", $fileContent);
        self::assertStringContainsString('tx_dbdoctortest_defined', $fileContent);
    }

    #[Test]
    public function executeRemovesEmptyFileOnCleanDatabase(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function executeStopsAtFailingStatement(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        unlink($file);
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->executeStatement('INSERT INTO tx_dbdoctortest_defined (uid, title) VALUES (1, \'same\'), (2, \'same\')');
        // A unique index on duplicate values can not be added
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (title));');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Error->value, $result & HealthCheckResult::Error->value);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('then a statement failed, execution stopped.', $display);
        self::assertStringContainsString('tx_dbdoctortest_unique', $display);
    }

    #[Test]
    public function executeWithLevelAllRenamesUnusedTableButDoesNotDropItInTheSameRun(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->createUndefinedTableWithTwoRows();
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file, '--level' => 'all']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertFalse($this->tableExists('tx_dbdoctortest_undefined'));
        self::assertTrue($this->tableExists('zzz_deleted_tx_dbdoctortest_undefined'));
        self::assertStringContainsString('Statements left to update the database schema with level "all": 1.', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function executeWithLevelAllDropsObsoleteTable(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->executeStatement('CREATE TABLE zzz_deleted_tx_dbdoctortest_undefined (uid INT)');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file, '--level' => 'all']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertFalse($this->tableExists('zzz_deleted_tx_dbdoctortest_undefined'));
        self::assertStringStartsWith('# dbdoctor:schema obsolete' . "\n", (string)file_get_contents($file));
    }

    #[Test]
    public function interactiveExecutesCategory(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->setInputs(['e']);
        $result = $commandTester->execute(['--mode' => 'interactive']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertTrue($this->tableExists('tx_dbdoctortest_defined'));
    }

    #[Test]
    public function interactiveSkipsCategory(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->setInputs(['n']);
        $result = $commandTester->execute(['--mode' => 'interactive']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertFalse($this->tableExists('tx_dbdoctortest_defined'));
        self::assertStringContainsString('Statements left to update the database schema with level "recommended": 1.', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function interactiveAborts(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->setInputs(['a']);
        $result = $commandTester->execute(['--mode' => 'interactive']);
        self::assertSame(HealthCheckResult::Abort->value, $result);
        self::assertFalse($this->tableExists('tx_dbdoctortest_defined'));
    }

    #[Test]
    public function interactiveShowsHelpForUnknownAnswer(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->setInputs(['x', 'n']);
        $commandTester->execute(['--mode' => 'interactive']);
        self::assertStringContainsString('n - NEXT category, do not execute these statements', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsRowsThatConflictWithChanges(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        unlink($file);
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->executeStatement('INSERT INTO tx_dbdoctortest_defined (uid, title) VALUES (1, \'same\'), (2, \'same\')');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (title));');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('Rows that conflict with changes', $display);
        self::assertStringContainsString('tx_dbdoctortest_defined.title: 2 rows share values, unique index "tx_dbdoctortest_unique', $display);
    }

    #[Test]
    public function executeReportsRowsThatConflictWithChangesBeforeExecuting(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        unlink($file);
        $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->executeStatement('INSERT INTO tx_dbdoctortest_defined (uid, title) VALUES (1, \'same\'), (2, \'same\')');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (title));');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:schema'));
        $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertLessThan(
            strpos($display, 'then a statement failed, execution stopped.'),
            strpos($display, 'Rows that conflict with changes')
        );
    }
}
