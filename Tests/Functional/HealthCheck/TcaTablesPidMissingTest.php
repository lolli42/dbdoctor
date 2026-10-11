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
use Lolli\Dbdoctor\HealthCheck\TcaTablesPidMissing;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TcaTablesPidMissingTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesPidMissing $subject */
        $subject = $this->get(TcaTablesPidMissing::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        /** @var TcaTablesPidMissing $subject */
        $subject = $this->get(TcaTablesPidMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingFixed.csv');
    }

    #[Test]
    public function fixBrokenRecordsInteractive(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('s', 'e');
        /** @var TcaTablesPidMissing $subject */
        $subject = $this->get(TcaTablesPidMissing::class);
        $result = $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
        self::assertSame(HealthCheckInterface::RESULT_BROKEN, $result);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingFixed.csv');
    }

    #[Test]
    public function executeInteractiveReloadsWhenRecordsChangedMeanwhile(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $io = $this->createMock(SymfonyStyle::class);
        $warnings = [];
        $io->method('warning')->willReturnCallback(
            function (string|array $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );
        $askCount = 0;
        $io->expects(self::exactly(2))->method('ask')->willReturnCallback(
            function () use ($connection, &$askCount): string {
                $askCount++;
                if ($askCount === 1) {
                    // Another broken record is added after the findings have been shown
                    $connection->insert('be_users', ['uid' => 4, 'pid' => 7, 'username' => 'Not ok user on pid 7']);
                } else {
                    // The first "e" did not execute
                    self::assertSame(1, $connection->count('*', 'be_users', ['uid' => 3]));
                }
                return 'e';
            }
        );
        /** @var TcaTablesPidMissing $subject */
        $subject = $this->get(TcaTablesPidMissing::class);
        $result = $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
        self::assertSame(HealthCheckInterface::RESULT_BROKEN, $result);
        self::assertContains('Records changed since they were shown, findings reloaded. Nothing executed.', $warnings);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingFixed.csv');
    }
}
