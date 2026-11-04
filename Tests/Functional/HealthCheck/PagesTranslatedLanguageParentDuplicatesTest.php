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
use Lolli\Dbdoctor\HealthCheck\PagesTranslatedLanguageParentDuplicates;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class PagesTranslatedLanguageParentDuplicatesTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDuplicatesImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var PagesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDuplicates::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDuplicatesImport.csv');
        /** @var PagesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDuplicates::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(9, $findings);
        self::assertEquals(new RecordKey('pages', 27), $findings[0]->key);
        self::assertSame('duplicate', $findings[0]->reason?->code);
        self::assertSame(['kept' => 28], $findings[0]->reason->parameters);
        self::assertSame(['kept' => 28], $findings[0]->evidence);
        self::assertEquals(new RecordKey('pages', 3), $findings[1]->key);
        self::assertSame('duplicate', $findings[1]->reason?->code);
        self::assertSame(['kept' => 5], $findings[1]->reason->parameters);
        self::assertSame(['kept' => 5], $findings[1]->evidence);
        self::assertEquals(new RecordKey('pages', 4), $findings[2]->key);
        self::assertSame('duplicate', $findings[2]->reason?->code);
        self::assertSame(['kept' => 5], $findings[2]->reason->parameters);
        self::assertSame(['kept' => 5], $findings[2]->evidence);
        self::assertEquals(new RecordKey('pages', 10), $findings[3]->key);
        self::assertSame('duplicate', $findings[3]->reason?->code);
        self::assertSame(['kept' => 11], $findings[3]->reason->parameters);
        self::assertSame(['kept' => 11], $findings[3]->evidence);
        self::assertEquals(new RecordKey('pages', 14), $findings[4]->key);
        self::assertSame('duplicate', $findings[4]->reason?->code);
        self::assertSame(['kept' => 13], $findings[4]->reason->parameters);
        self::assertSame(['kept' => 13], $findings[4]->evidence);
        self::assertEquals(new RecordKey('pages', 17), $findings[5]->key);
        self::assertSame('duplicate', $findings[5]->reason?->code);
        self::assertSame(['kept' => 16], $findings[5]->reason->parameters);
        self::assertSame(['kept' => 16], $findings[5]->evidence);
        self::assertEquals(new RecordKey('pages', 20), $findings[6]->key);
        self::assertSame('duplicate', $findings[6]->reason?->code);
        self::assertSame(['kept' => 19], $findings[6]->reason->parameters);
        self::assertSame(['kept' => 19], $findings[6]->evidence);
        self::assertEquals(new RecordKey('pages', 22), $findings[7]->key);
        self::assertSame('duplicate', $findings[7]->reason?->code);
        self::assertSame(['kept' => 23], $findings[7]->reason->parameters);
        self::assertSame(['kept' => 23], $findings[7]->evidence);
        self::assertEquals(new RecordKey('pages', 25), $findings[8]->key);
        self::assertSame('duplicate', $findings[8]->reason?->code);
        self::assertSame(['kept' => 26], $findings[8]->reason->parameters);
        self::assertSame(['kept' => 26], $findings[8]->evidence);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDuplicatesImport.csv');
        /** @var PagesTranslatedLanguageParentDuplicates $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDuplicates::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDuplicatesFixed.csv');
    }
}
