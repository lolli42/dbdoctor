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
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class TableHelperTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function tableExistsInDatabaseReturnTrueForExistingTable(): void
    {
        self::assertTrue($this->get(TableHelper::class)->tableExistsInDatabase('pages'));
    }

    #[Test]
    public function tableExistsInDatabaseReturnFalseForEmptyString(): void
    {
        self::assertFalse($this->get(TableHelper::class)->tableExistsInDatabase(''));
    }

    #[Test]
    public function tableExistsInDatabaseReturnFalseForNotExistingTable(): void
    {
        self::assertFalse($this->get(TableHelper::class)->tableExistsInDatabase('i_do_not_exist'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseWithEmptyTableName(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldExistsInTable('', 'foo'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseWithEmptyFieldName(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldExistsInTable('foo', ''));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseIfTableDoesNotExist(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldExistsInTable('table-does-not-exist', 'uid'));
    }

    #[Test]
    public function fieldExistsInTableReturnsFalseIfFieldDoesNotExist(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldExistsInTable('pages', 'field-does-not-exist'));
    }

    #[Test]
    public function fieldExistsInTableReturnsTrueIfFieldDoesExist(): void
    {
        self::assertTrue($this->get(TableHelper::class)->fieldExistsInTable('pages', 'title'));
    }

    #[Test]
    public function fieldIsIntegerReturnsFalseIfFieldDoesNotExist(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldIsInteger('pages', 'field-does-not-exist'));
    }

    #[Test]
    public function fieldIsIntegerReturnsFalseForTextField(): void
    {
        self::assertFalse($this->get(TableHelper::class)->fieldIsInteger('pages', 'title'));
    }

    #[Test]
    public function fieldIsIntegerReturnsTrueForIntegerField(): void
    {
        self::assertTrue($this->get(TableHelper::class)->fieldIsInteger('pages', 'shortcut'));
    }
}
