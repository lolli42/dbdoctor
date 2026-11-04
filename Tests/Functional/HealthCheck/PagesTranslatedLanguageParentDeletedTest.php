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
use Lolli\Dbdoctor\HealthCheck\PagesTranslatedLanguageParentDeleted;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class PagesTranslatedLanguageParentDeletedTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var PagesTranslatedLanguageParentDeleted $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDeleted::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedImport.csv');
        /** @var PagesTranslatedLanguageParentDeleted $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDeleted::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(3, $findings);
        self::assertEquals(new RecordKey('pages', 5), $findings[0]->key);
        self::assertSame('parent-deleted', $findings[0]->reason?->code);
        self::assertSame(['parent' => 4], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('pages', 6), $findings[1]->key);
        self::assertSame('parent-deleted', $findings[1]->reason?->code);
        self::assertSame(['parent' => 4], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('pages', 7), $findings[2]->key);
        self::assertSame('parent-deleted', $findings[2]->reason?->code);
        self::assertSame(['parent' => 4], $findings[2]->reason->parameters);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedImport.csv');
        /** @var PagesTranslatedLanguageParentDeleted $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDeleted::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedFixed.csv');
    }

    #[Test]
    public function checkModeSkipsCheckIfEarlierCheckDidNotFix(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedEarlierCheckNotFixedImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var PagesTranslatedLanguageParentDeleted $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDeleted::class);
        $io->expects(self::once())->method('warning');
        self::assertSame(HealthCheckResult::Broken, $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Check, ''));
    }

    #[Test]
    public function executeModeThrowsIfEarlierCheckDidNotFix(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesTranslatedLanguageParentDeletedEarlierCheckNotFixedImport.csv');
        /** @var PagesTranslatedLanguageParentDeleted $subject */
        $subject = $this->get(PagesTranslatedLanguageParentDeleted::class);
        $this->expectException(EarlierCheckNotFixedException::class);
        $this->expectExceptionCode(1647793648);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
    }
}
