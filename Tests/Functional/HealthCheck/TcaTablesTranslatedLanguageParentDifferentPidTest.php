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
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\TcaTablesTranslatedLanguageParentDifferentPid;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TcaTablesTranslatedLanguageParentDifferentPidTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(18, $findings);
        self::assertEquals(new RecordKey('tt_content', 6), $findings[0]->key);
        self::assertSame('parent-different-pid', $findings[0]->reason?->code);
        self::assertSame(['parent' => 5, 'parentPid' => 2], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 9), $findings[1]->key);
        self::assertSame('parent-different-pid', $findings[1]->reason?->code);
        self::assertSame(['parent' => 7, 'parentPid' => 2], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 11), $findings[2]->key);
        self::assertSame('parent-different-pid', $findings[2]->reason?->code);
        self::assertSame(['parent' => 10, 'parentPid' => 2], $findings[2]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 14), $findings[3]->key);
        self::assertSame('parent-different-pid', $findings[3]->reason?->code);
        self::assertSame(['parent' => 12, 'parentPid' => 2], $findings[3]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 17), $findings[4]->key);
        self::assertSame('parent-different-pid', $findings[4]->reason?->code);
        self::assertSame(['parent' => 15, 'parentPid' => 2], $findings[4]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 20), $findings[5]->key);
        self::assertSame('parent-different-pid', $findings[5]->reason?->code);
        self::assertSame(['parent' => 18, 'parentPid' => 2], $findings[5]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 23), $findings[6]->key);
        self::assertSame('parent-different-pid', $findings[6]->reason?->code);
        self::assertSame(['parent' => 21, 'parentPid' => 2], $findings[6]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 29), $findings[7]->key);
        self::assertSame('parent-different-pid', $findings[7]->reason?->code);
        self::assertSame(['parent' => 28, 'parentPid' => 2], $findings[7]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 30), $findings[8]->key);
        self::assertSame('parent-different-pid', $findings[8]->reason?->code);
        self::assertSame(['parent' => 28, 'parentPid' => 2], $findings[8]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 32), $findings[9]->key);
        self::assertSame('parent-different-pid', $findings[9]->reason?->code);
        self::assertSame(['parent' => 31, 'parentPid' => 2], $findings[9]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 33), $findings[10]->key);
        self::assertSame('parent-different-pid', $findings[10]->reason?->code);
        self::assertSame(['parent' => 31, 'parentPid' => 2], $findings[10]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 35), $findings[11]->key);
        self::assertSame('parent-different-pid', $findings[11]->reason?->code);
        self::assertSame(['parent' => 34, 'parentPid' => 2], $findings[11]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 36), $findings[12]->key);
        self::assertSame('parent-different-pid', $findings[12]->reason?->code);
        self::assertSame(['parent' => 34, 'parentPid' => 2], $findings[12]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 38), $findings[13]->key);
        self::assertSame('parent-different-pid', $findings[13]->reason?->code);
        self::assertSame(['parent' => 37, 'parentPid' => 2], $findings[13]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 39), $findings[14]->key);
        self::assertSame('parent-different-pid', $findings[14]->reason?->code);
        self::assertSame(['parent' => 37, 'parentPid' => 2], $findings[14]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 41), $findings[15]->key);
        self::assertSame('parent-different-pid', $findings[15]->reason?->code);
        self::assertSame(['parent' => 40, 'parentPid' => 2], $findings[15]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 44), $findings[16]->key);
        self::assertSame('parent-different-pid', $findings[16]->reason?->code);
        self::assertSame(['parent' => 42, 'parentPid' => 2], $findings[16]->reason->parameters);
        self::assertEquals(new RecordKey('tt_content', 46), $findings[17]->key);
        self::assertSame('parent-different-pid', $findings[17]->reason?->code);
        self::assertSame(['parent' => 45, 'parentPid' => 2], $findings[17]->reason->parameters);
    }

    #[Test]
    public function localizationOfParentWithUidOneIsFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidParentUidOneImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(1, $findings);
        self::assertEquals(new RecordKey('tt_content', 2), $findings[0]->key);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidFixed.csv');
    }

    #[Test]
    public function fixBrokenRecordsTtContentNotHiddenAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareFixed.csv');
    }

    #[Test]
    public function fixBrokenRecordsTtContentNotHiddenNotDeleteAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        unset($GLOBALS['TCA']['tt_content']['ctrl']['delete']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareFixed.csv');
    }

    #[Test]
    public function executeModeThrowsIfEarlierCheckDidNotFix(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidEarlierCheckNotFixedImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $this->expectException(EarlierCheckNotFixedException::class);
        $this->expectExceptionCode(1648031986);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
    }
}
