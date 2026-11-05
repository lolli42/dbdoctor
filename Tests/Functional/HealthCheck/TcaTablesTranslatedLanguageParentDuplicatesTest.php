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
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\TcaTablesTranslatedLanguageParentDuplicates;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TcaTablesTranslatedLanguageParentDuplicatesTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDuplicatesImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDuplicates::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDuplicatesImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDuplicates::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(7, $findings);
        self::assertEquals(new RecordKey('sys_file_reference', 3), $findings[0]->key);
        self::assertSame('duplicate', $findings[0]->reason?->code);
        self::assertSame(['kept' => 2], $findings[0]->reason->parameters);
        self::assertSame(['kept' => 2], $findings[0]->evidence);
        self::assertEquals(new RecordKey('sys_file_reference', 10), $findings[1]->key);
        self::assertSame('duplicate', $findings[1]->reason?->code);
        self::assertSame(['kept' => 8], $findings[1]->reason->parameters);
        self::assertSame(['kept' => 8], $findings[1]->evidence);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 3), $findings[2]->key);
        self::assertSame('duplicate', $findings[2]->reason?->code);
        self::assertSame(['kept' => 2], $findings[2]->reason->parameters);
        self::assertSame(['kept' => 2], $findings[2]->evidence);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 4), $findings[3]->key);
        self::assertSame('duplicate', $findings[3]->reason?->code);
        self::assertSame(['kept' => 2], $findings[3]->reason->parameters);
        self::assertSame(['kept' => 2], $findings[3]->evidence);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 8), $findings[4]->key);
        self::assertSame('duplicate', $findings[4]->reason?->code);
        self::assertSame(['kept' => 9], $findings[4]->reason->parameters);
        self::assertSame(['kept' => 9], $findings[4]->evidence);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 10), $findings[5]->key);
        self::assertSame('duplicate', $findings[5]->reason?->code);
        self::assertSame(['kept' => 9], $findings[5]->reason->parameters);
        self::assertSame(['kept' => 9], $findings[5]->evidence);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 13), $findings[6]->key);
        self::assertSame('duplicate', $findings[6]->reason?->code);
        self::assertSame(['kept' => 12], $findings[6]->reason->parameters);
        self::assertSame(['kept' => 12], $findings[6]->evidence);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDuplicatesImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDuplicates::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDuplicatesFixed.csv');
    }
}
