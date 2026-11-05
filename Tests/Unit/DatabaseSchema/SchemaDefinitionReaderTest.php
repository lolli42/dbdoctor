<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Unit\DatabaseSchema;

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

use Lolli\Dbdoctor\DatabaseSchema\SchemaDefinitionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SchemaDefinitionReaderTest extends UnitTestCase
{
    public static function quoteNumberDefaultsDataProvider(): \Generator
    {
        yield 'double' => [
            'CREATE TABLE t (price double DEFAULT 0 NOT NULL);',
            'CREATE TABLE t (price double DEFAULT \'0\' NOT NULL);',
        ];
        yield 'double precision with length, unsigned' => [
            'CREATE TABLE t (price double precision(11,2) unsigned DEFAULT 0.5 NOT NULL);',
            'CREATE TABLE t (price double precision(11,2) unsigned DEFAULT \'0.5\' NOT NULL);',
        ];
        yield 'float, negative' => [
            'CREATE TABLE t (rate float DEFAULT -1 NOT NULL);',
            'CREATE TABLE t (rate float DEFAULT \'-1\' NOT NULL);',
        ];
        yield 'decimal' => [
            'CREATE TABLE t (amount decimal(10,2) DEFAULT 0 NOT NULL);',
            'CREATE TABLE t (amount decimal(10,2) DEFAULT \'0\' NOT NULL);',
        ];
        yield 'varchar, NOT NULL before DEFAULT' => [
            'CREATE TABLE t (code varchar(6) NOT NULL DEFAULT 0);',
            'CREATE TABLE t (code varchar(6) NOT NULL DEFAULT \'0\');',
        ];
        yield 'char, upper case, last column' => [
            'CREATE TABLE t (CODE CHAR(2) DEFAULT 1)',
            'CREATE TABLE t (CODE CHAR(2) DEFAULT \'1\')',
        ];
        yield 'several columns, multiple lines' => [
            "CREATE TABLE t (\n\tuid int(11) DEFAULT 0 NOT NULL,\n\tcode varchar(6) DEFAULT 0 NOT NULL,\n\tprice double DEFAULT 0 NOT NULL\n);",
            "CREATE TABLE t (\n\tuid int(11) DEFAULT 0 NOT NULL,\n\tcode varchar(6) DEFAULT '0' NOT NULL,\n\tprice double DEFAULT '0' NOT NULL\n);",
        ];
        yield 'integer column kept' => [
            'CREATE TABLE t (uid int(11) unsigned DEFAULT 0 NOT NULL);',
            'CREATE TABLE t (uid int(11) unsigned DEFAULT 0 NOT NULL);',
        ];
        yield 'string literal kept' => [
            'CREATE TABLE t (code varchar(6) DEFAULT \'0\' NOT NULL);',
            'CREATE TABLE t (code varchar(6) DEFAULT \'0\' NOT NULL);',
        ];
        yield 'text column kept' => [
            'CREATE TABLE t (bodytext text);',
            'CREATE TABLE t (bodytext text);',
        ];
    }

    #[DataProvider('quoteNumberDefaultsDataProvider')]
    #[Test]
    public function quoteNumberDefaults(string $statement, string $expected): void
    {
        self::assertSame($expected, (new SchemaDefinitionReader(self::createStub(SqlReader::class)))->quoteNumberDefaults($statement));
    }
}
