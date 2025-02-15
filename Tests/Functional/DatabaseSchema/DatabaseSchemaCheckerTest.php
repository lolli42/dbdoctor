<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\DatabaseSchema;

use Lolli\Dbdoctor\DatabaseSchema\DatabaseSchemaChecker;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DatabaseSchemaCheckerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    private bool $addSqlDataDispatched = false;

    protected function tearDown(): void
    {
        $this->addSqlDataDispatched = false;
        parent::tearDown();
    }

    private function addSqlDataToSchemaMigratorListenToAlterTableDefinitionStatementsEvent(string ...$sqlStatements): void
    {
        $self = $this;
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'database-schema-checker-test',
            static function (AlterTableDefinitionStatementsEvent $event) use ($sqlStatements, $self): void {
                $self->addSqlDataDispatched = true;
                array_map(fn(string $sql) => $event->addSqlData($sql), $sqlStatements);
            }
        );
        $listenerProvider = $container->get(ListenerProvider::class);
        $listenerProvider->addListener(AlterTableDefinitionStatementsEvent::class, 'database-schema-checker-test');
    }

    #[Test]
    public function hasIncompleteTablesColumnsIndexesReturnsFalseOnCleanDatabase(): void
    {
        // Note that failing test may also indicate that database is not clean.
        self::assertFalse($this->get(DatabaseSchemaChecker::class)->hasIncompleteTablesColumnsIndexes());
    }

    #[Test]
    public function hasIncompleteTablesColumnsIndexesReturnsTrueWhenHavingTablesToCreate(): void
    {
        $this->addSqlDataToSchemaMigratorListenToAlterTableDefinitionStatementsEvent(
            'CREATE TABLE a_new_table (uid INT(11) DEFAULT 0 NOT NULL);',
        );
        self::assertTrue($this->get(DatabaseSchemaChecker::class)->hasIncompleteTablesColumnsIndexes());
        self::assertTrue($this->addSqlDataDispatched);
    }

    #[Test]
    public function hasIncompleteTablesColumnsIndexesReturnsTrueWhenHavingColumnsToAdd(): void
    {
        $this->addSqlDataToSchemaMigratorListenToAlterTableDefinitionStatementsEvent(
            'CREATE TABLE pages (some_field VARCHAR(10) DEFAULT \'\' NOT NULL);',
        );
        self::assertTrue($this->get(DatabaseSchemaChecker::class)->hasIncompleteTablesColumnsIndexes());
        self::assertTrue($this->addSqlDataDispatched);
    }

    #[Test]
    public function hasIncompleteTablesColumnsIndexesReturnsTrueWhenHavingIndexToAdd(): void
    {
        $this->addSqlDataToSchemaMigratorListenToAlterTableDefinitionStatementsEvent(
            'CREATE TABLE pages (KEY idx_doktype (`doktype`));',
        );
        self::assertTrue($this->get(DatabaseSchemaChecker::class)->hasIncompleteTablesColumnsIndexes());
        self::assertTrue($this->addSqlDataDispatched);
    }
}
