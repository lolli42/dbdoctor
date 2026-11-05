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
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\TtContentLocalizedParentDifferentPid;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TtContentLocalizedParentDifferentPidTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidImport.csv');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(8, $findings);
        self::assertEquals(new RecordKey('tt_content', 7), $findings[0]->key);
        self::assertSame('parent-different-pid', $findings[0]->reason?->code);
        self::assertSame(['parent' => 2, 'parentPid' => 2], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 8), $findings[1]->key);
        self::assertSame('parent-different-pid', $findings[1]->reason?->code);
        self::assertSame(['parent' => 2, 'parentPid' => 2], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 10), $findings[2]->key);
        self::assertSame('parent-different-pid', $findings[2]->reason?->code);
        self::assertSame(['parent' => 9, 'parentPid' => 2], $findings[2]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 14), $findings[3]->key);
        self::assertSame('parent-different-pid', $findings[3]->reason?->code);
        self::assertSame(['parent' => 11, 'parentPid' => 2], $findings[3]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 23), $findings[4]->key);
        self::assertSame('parent-different-pid', $findings[4]->reason?->code);
        self::assertSame(['parent' => 2, 'parentPid' => 2], $findings[4]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 24), $findings[5]->key);
        self::assertSame('parent-different-pid', $findings[5]->reason?->code);
        self::assertSame(['parent' => 2, 'parentPid' => 2], $findings[5]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 25), $findings[6]->key);
        self::assertSame('parent-different-pid', $findings[6]->reason?->code);
        self::assertSame(['parent' => 2, 'parentPid' => 2], $findings[6]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 31), $findings[7]->key);
        self::assertSame('parent-different-pid', $findings[7]->reason?->code);
        self::assertSame(['parent' => 28, 'parentPid' => 2], $findings[7]->reason->parameters);
    }

    #[Test]
    public function localizationOfParentWithUidOneIsFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidParentUidOneImport.csv');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(1, $findings);
        self::assertEquals(new RecordKey('tt_content', 2), $findings[0]->key);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidImport.csv');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidFixed.csv');
    }

    #[Test]
    public function checkModeSkipsCheckWithUnexpectedT3verState(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidStateMinusOneImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::once())->method('warning');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $result = $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Check, '');
        self::assertSame(HealthCheckResult::Broken, $result);
    }

    #[Test]
    public function executeModeThrowsWithUnexpectedT3verState(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidStateMinusOneImport.csv');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $this->expectException(EarlierCheckNotFixedException::class);
        $this->expectExceptionCode(1689082419);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
    }

    #[Test]
    public function executeModeThrowsIfEarlierCheckDidNotFix(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedParentDifferentPidEarlierCheckNotFixedImport.csv');
        /** @var TtContentLocalizedParentDifferentPid $subject */
        $subject = $this->get(TtContentLocalizedParentDifferentPid::class);
        $this->expectException(EarlierCheckNotFixedException::class);
        $this->expectExceptionCode(1689066707);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
    }
}
