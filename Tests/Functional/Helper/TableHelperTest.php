<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Helper;

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
use Lolli\Dbdoctor\Helper\TableHelper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TableHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    #[Test]
    public function tableExistsInDatabaseReturnTrueForExistingTable(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertTrue((new TableHelper($connectionPool))->tableExistsInDatabase('pages'));
    }

    #[Test]
    public function tableExistsInDatabaseReturnFalseForEmptyString(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->tableExistsInDatabase(''));
    }

    #[Test]
    public function tableExistsInDatabaseReturnFalseForNotExistingTable(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->tableExistsInDatabase('i_do_not_exist'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseWithEmptyTableName(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->fieldExistsInTable('', 'foo'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseWithEmptyFieldName(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->fieldExistsInTable('foo', ''));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseIfTableDoesNotExist(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->fieldExistsInTable('table-does-not-exist', 'uid'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseIfFieldDoesNotExist(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertFalse((new TableHelper($connectionPool))->fieldExistsInTable('pages', 'field-does-not-exist'));
    }

    #[Test]
    public function fieldExistsInTableReturnsTrueIfFieldDoesExist(): void
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertTrue((new TableHelper($connectionPool))->fieldExistsInTable('pages', 'title'));
    }
}
