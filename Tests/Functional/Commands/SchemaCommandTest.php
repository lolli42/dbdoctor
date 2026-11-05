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
}
