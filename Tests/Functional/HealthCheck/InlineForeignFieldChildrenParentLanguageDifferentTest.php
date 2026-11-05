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
use Lolli\Dbdoctor\HealthCheck\InlineForeignFieldChildrenParentLanguageDifferent;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class InlineForeignFieldChildrenParentLanguageDifferentTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentLanguageDifferentTestImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var InlineForeignFieldChildrenParentLanguageDifferent $subject */
        $subject = $this->get(InlineForeignFieldChildrenParentLanguageDifferent::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentLanguageDifferentTestImport.csv');
        /** @var InlineForeignFieldChildrenParentLanguageDifferent $subject */
        $subject = $this->get(InlineForeignFieldChildrenParentLanguageDifferent::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        // Updates of a table before its deletes
        self::assertCount(5, $findings);
        self::assertEquals(new RecordKey('sys_file_reference', 15), $findings[0]->key);
        self::assertSame('all-languages-parent-language', $findings[0]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2, 'parentLanguage' => 1], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_reference', 3), $findings[1]->key);
        self::assertSame('parent-language-different', $findings[1]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 3, 'parentLanguage' => 1], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_reference', 4), $findings[2]->key);
        self::assertSame('parent-language-different', $findings[2]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 1, 'parentLanguage' => 0], $findings[2]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_reference', 17), $findings[3]->key);
        self::assertSame('parent-language-different', $findings[3]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 3, 'parentLanguage' => 1], $findings[3]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_reference', 18), $findings[4]->key);
        self::assertSame('parent-language-different', $findings[4]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 1, 'parentLanguage' => 0], $findings[4]->reason->parameters);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentLanguageDifferentTestImport.csv');
        /** @var InlineForeignFieldChildrenParentLanguageDifferent $subject */
        $subject = $this->get(InlineForeignFieldChildrenParentLanguageDifferent::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentLanguageDifferentTestFixed.csv');
    }
}
