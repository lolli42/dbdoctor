<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\HealthCheck;

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

use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\PagesTranslatedLanguageParentDeleted;
use Lolli\Dbdoctor\HealthCheck\TcaTablesDeleteFlagZeroOrOne;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class SqlDumpFileHeaderTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected function tearDown(): void
    {
        @unlink($this->instancePath . '/dbdoctor-dump.sql');
        parent::tearDown();
    }

    #[Test]
    public function headerIsWrittenPerPassOfACheckWithMultipleChainPositions(): void
    {
        $file = $this->instancePath . '/dbdoctor-dump.sql';
        // The same instance runs twice, as a service with two tags does in the chain.
        /** @var PagesTranslatedLanguageParentDeleted $multiPositionCheck */
        $multiPositionCheck = $this->get(PagesTranslatedLanguageParentDeleted::class);
        /** @var TcaTablesDeleteFlagZeroOrOne $otherCheck */
        $otherCheck = $this->get(TcaTablesDeleteFlagZeroOrOne::class);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SqlDumpFileHeaderFirstPassImport.csv');
        $multiPositionCheck->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, $file);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SqlDumpFileHeaderOtherCheckImport.csv');
        $otherCheck->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, $file);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SqlDumpFileHeaderSecondPassImport.csv');
        $multiPositionCheck->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, $file);
        // SQL quoting differs per DBMS, the header lines are checked exactly.
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(6, $lines);
        self::assertSame('# Triggered by ' . PagesTranslatedLanguageParentDeleted::class, $lines[0]);
        self::assertSame('# Triggered by ' . TcaTablesDeleteFlagZeroOrOne::class, $lines[2]);
        self::assertSame('# Triggered by ' . PagesTranslatedLanguageParentDeleted::class, $lines[4]);
    }
}
