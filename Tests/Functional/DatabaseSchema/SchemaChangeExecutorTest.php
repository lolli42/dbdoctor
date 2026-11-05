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

use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeCategory;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeExecutor;
use Lolli\Dbdoctor\DatabaseSchema\SchemaInspector;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

final class SchemaChangeExecutorTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        // The test instance is shared with other test classes: Leave the schema as it was.
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('DROP TABLE IF EXISTS tx_dbdoctortest_defined');
        @unlink($this->instancePath . '/dbdoctor-file');
        parent::tearDown();
    }

    private function addTableDefinition(string $sql): void
    {
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'schema-change-executor-test',
            static function (AlterTableDefinitionStatementsEvent $event) use ($sql): void {
                $event->addSqlData($sql);
            }
        );
        $this->get(ListenerProvider::class)->addListener(AlterTableDefinitionStatementsEvent::class, 'schema-change-executor-test');
    }

    #[Test]
    public function executeExecutesStatementsAndLogsThem(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $changes = $this->get(SchemaInspector::class)->getChanges();
        $execution = $this->get(SchemaChangeExecutor::class)->execute($changes, $file);
        self::assertSame(count($changes), $execution->executedCount);
        self::assertNull($execution->failedChange);
        self::assertSame('', $execution->errorMessage);
        self::assertSame([], $this->get(SchemaInspector::class)->getChanges());
        self::assertSame($changes[0]->statement . ";\n", file_get_contents($file));
    }

    #[Test]
    public function executeWritesNoFileWithoutFileName(): void
    {
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $execution = $this->get(SchemaChangeExecutor::class)->execute($this->get(SchemaInspector::class)->getChanges(), '');
        self::assertSame(1, $execution->executedCount);
        self::assertFileDoesNotExist($this->instancePath . '/dbdoctor-file');
    }

    #[Test]
    public function executeStopsAtFirstFailingStatement(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL);');
        $this->get(SchemaChangeExecutor::class)->execute($this->get(SchemaInspector::class)->getChanges(), '');
        $connection->executeStatement('INSERT INTO tx_dbdoctortest_defined (uid, title) VALUES (1, \'same\'), (2, \'same\')');
        // A unique index on duplicate values can not be added, then a column is missing in pages
        $this->addTableDefinition(
            'CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL, title VARCHAR(10) DEFAULT \'\' NOT NULL, UNIQUE KEY tx_dbdoctortest_unique (title));'
            . "\n" . 'CREATE TABLE pages (tx_dbdoctortest_field VARCHAR(10) DEFAULT \'\' NOT NULL);'
        );
        $changes = $this->get(SchemaInspector::class)->getChanges();
        $failing = array_values(array_filter($changes, static fn($change): bool => str_contains($change->statement, 'tx_dbdoctortest_unique')));
        $other = array_values(array_filter($changes, static fn($change): bool => str_contains($change->statement, 'tx_dbdoctortest_field')));
        self::assertCount(1, $failing);
        self::assertCount(1, $other);
        $execution = $this->get(SchemaChangeExecutor::class)->execute([$failing[0], $other[0]], '');
        self::assertSame(0, $execution->executedCount);
        self::assertSame($failing[0], $execution->failedChange);
        self::assertNotSame('', $execution->errorMessage);
        $missing = array_filter(
            $this->get(SchemaInspector::class)->getChanges(),
            static fn($change): bool => $change->category === SchemaChangeCategory::Missing && str_contains($change->statement, 'tx_dbdoctortest_field')
        );
        self::assertNotEmpty($missing, 'Statement after the failing one has not been executed');
    }

    #[Test]
    public function executeFlushesRuntimeCache(): void
    {
        $runtimeCache = $this->get('cache.runtime');
        $runtimeCache->set('dbdoctor-test', 'value');
        $this->addTableDefinition('CREATE TABLE tx_dbdoctortest_defined (uid INT(11) DEFAULT 0 NOT NULL);');
        $this->get(SchemaChangeExecutor::class)->execute($this->get(SchemaInspector::class)->getChanges(), '');
        self::assertFalse($runtimeCache->has('dbdoctor-test'));
    }

    #[Test]
    public function executeKeepsRuntimeCacheWithoutStatements(): void
    {
        $runtimeCache = $this->get('cache.runtime');
        $runtimeCache->set('dbdoctor-test', 'value');
        $this->get(SchemaChangeExecutor::class)->execute([], '');
        self::assertTrue($runtimeCache->has('dbdoctor-test'));
    }
}
