<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional;

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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\TestingFramework\Core\Testbase;

/**
 * HACK: Base class of all dbdoctor functional tests, working around the
 * per test case setup of typo3/testing-framework.
 *
 * testing-framework sets up a fresh instance - directories, links,
 * settings.php, PackageStates.php, database and full database schema - for
 * the first test of each test case class. Only subsequent tests of the same
 * class reuse that instance and just truncate tables. This is the main cost
 * of a functional test run: dbdoctor test classes have only two or three
 * tests each, and the setup of the first test takes ~1s, while each further
 * test takes ~0.03s.
 *
 * Most dbdoctor test classes load the very same extensions, so their
 * instances and database schemas are identical. This class reuses them:
 * - getInstanceIdentifier() hashes the properties that shape an instance
 *   instead of the class name. Test classes with identical setup share
 *   instance directory and database.
 * - setUp() tells testing-framework "not the first test of this test case"
 *   if an instance with the same identifier has already been set up in
 *   this run. testing-framework then takes its "subsequent test" path: boot
 *   the existing instance and truncate its tables. When switching to a
 *   different instance, setUp() closes the database connection ConnectionPool
 *   keeps in a static property, otherwise that path would continue to work on
 *   the database of the previous instance.
 *
 * This is a hack since testing-framework has no API for it: It writes the
 * private static FunctionalTestCase::$currentTestCaseClass and overrides
 * the @internal getInstanceIdentifier(). It may break with any
 * testing-framework update. If so, remove setUp() and
 * getInstanceIdentifier() from this class to fall back to default behavior.
 *
 * Instance setup must be defined by default values of the properties hashed
 * in getInstanceIdentifier(). Tests must not change them at runtime, and must
 * not leave files in the instance that affect other tests.
 *
 * Effect: Instead of one full setup per test case class, there is one per
 * distinct setup. Speedup of a full functional test run, measured locally
 * with core v14, PHP 8.4, 124 tests in 53 test case classes, 5 distinct
 * setups:
 * - sqlite:        47.1s -> 7.7s, factor 6.1
 * - mariadb 10.3:  50.0s -> 9.7s, factor 5.2
 * - postgres 10:   69.5s -> 22.9s, factor 3.0
 *
 * Each distinct setup still costs ~1s, mostly for building the dependency
 * injection container of the fresh instance. This class thus defines one
 * default setup for all test case classes: The core extensions and all
 * fixture extensions any test needs. Test case classes should not declare
 * $coreExtensionsToLoad or $testExtensionsToLoad, unless a test needs a
 * different setup, for instance to verify a check without workspaces loaded.
 * More tables in the instance are cheap compared to another setup.
 *
 * Second hack: initializeTestDatabaseAndTruncateTables() truncates only
 * tables a previous test changed on postgres, see there. It overrides an
 * @internal method of testing-framework as well. If it breaks, remove it
 * to fall back to truncating all tables.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    /**
     * Default setup shared by most test case classes, see class comment.
     */
    protected array $coreExtensionsToLoad = [
        'impexp',
        'redirects',
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestsforeignfield',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestsgroup',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestsgrouptype',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestsinline',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestsselect',
        __DIR__ . '/FixtureExtensions/tx_dbdoctortestssync',
    ];

    /**
     * @var array<string, true> Identifiers of instances set up in this run
     */
    private static array $setUpInstances = [];

    private static string $lastIdentifier = '';

    /**
     * @return non-empty-string
     */
    protected static function getInstanceIdentifier(): string
    {
        // Static scope: Use default property values of the test case class.
        $properties = (new \ReflectionClass(static::class))->getDefaultProperties();
        return substr(sha1(serialize([
            $properties['coreExtensionsToLoad'],
            $properties['testExtensionsToLoad'],
            $properties['pathsToLinkInTestInstance'],
            $properties['pathsToProvideInTestInstance'],
            $properties['configurationToUseInTestInstance'],
            $properties['additionalFoldersToCreate'],
            $properties['initializeDatabase'],
        ])), 0, 7);
    }

    protected function setUp(): void
    {
        $identifier = static::getInstanceIdentifier();
        if (isset(self::$setUpInstances[$identifier])) {
            if ($identifier !== self::$lastIdentifier) {
                $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
                $connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->close();
                $connectionPool->resetConnections();
            }
            // Make testing-framework believe this is not the first test of this test case class.
            \Closure::bind(
                static function (string $testCaseClass): void {
                    FunctionalTestCase::$currentTestCaseClass = $testCaseClass;
                },
                null,
                FunctionalTestCase::class
            )(static::class);
        }
        parent::setUp();
        self::$setUpInstances[$identifier] = true;
        self::$lastIdentifier = $identifier;
    }

    /**
     * Truncate only tables a previous test changed on postgres.
     *
     * testing-framework truncates all tables of the instance before each
     * subsequent test on postgres, with one TRUNCATE statement over all
     * tables, which costs ~25ms per test. On mysql, it truncates only tables
     * with rows or a changed auto increment value. This does the same on
     * postgres: Truncate tables with rows, and tables with a sequence that
     * has been used since it was reset. RESTART IDENTITY resets a sequence to
     * "not called", pg_sequences.last_value is NULL then, so the next test
     * starts with uid 1 again.
     */
    protected function initializeTestDatabaseAndTruncateTables(Testbase $testbase, bool $initializeDatabase, string $dbPathSqlite, string $dbPathSqliteEmpty): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (!$initializeDatabase || !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            parent::initializeTestDatabaseAndTruncateTables($testbase, $initializeDatabase, $dbPathSqlite, $dbPathSqliteEmpty);
            return;
        }
        $tableNames = $connection->executeQuery(
            'SELECT c.relname FROM pg_class c'
            . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE c.relkind = 'r' AND n.nspname = current_schema()"
        )->fetchFirstColumn();
        if ($tableNames === []) {
            return;
        }
        $tablesWithRowsQueries = [];
        foreach ($tableNames as $tableName) {
            $tablesWithRowsQueries[] = 'SELECT ' . $connection->quote($tableName) . ' AS table_name'
                . ' WHERE EXISTS (SELECT 1 FROM ' . $connection->quoteIdentifier($tableName) . ')';
        }
        $tablesWithRows = $connection->executeQuery(implode(' UNION ALL ', $tablesWithRowsQueries))->fetchFirstColumn();
        $tablesWithUsedSequence = $connection->executeQuery(
            'SELECT t.relname FROM pg_sequences ps'
            . ' JOIN pg_namespace n ON n.nspname = ps.schemaname'
            . ' JOIN pg_class s ON s.relname = ps.sequencename AND s.relnamespace = n.oid'
            . " JOIN pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')"
            . ' JOIN pg_class t ON t.oid = d.refobjid'
            . ' WHERE ps.schemaname = current_schema() AND ps.last_value IS NOT NULL'
        )->fetchFirstColumn();
        $tablesToTruncate = array_unique(array_merge($tablesWithRows, $tablesWithUsedSequence));
        if ($tablesToTruncate === []) {
            return;
        }
        $connection->executeStatement(
            'TRUNCATE TABLE ' . implode(', ', array_map($connection->quoteIdentifier(...), $tablesToTruncate)) . ' RESTART IDENTITY CASCADE'
        );
    }
}
