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

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

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
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
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
}
