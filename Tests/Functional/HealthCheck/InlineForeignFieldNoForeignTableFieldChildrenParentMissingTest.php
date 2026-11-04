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
use Lolli\Dbdoctor\HealthCheck\InlineForeignFieldNoForeignTableFieldChildrenParentMissing;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class InlineForeignFieldNoForeignTableFieldChildrenParentMissingTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentMissingTestImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentMissing $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentMissing::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentMissingTestImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentMissing $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentMissing::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(7, $findings);
        // Sorted by uid, although found by two parent fields
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 2), $findings[0]->key);
        self::assertSame('parent-missing', $findings[0]->reason?->code);
        self::assertSame(['parentTable' => 'pages', 'parentUid' => 2], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 4), $findings[1]->key);
        self::assertSame('parent-missing', $findings[1]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 6], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 6), $findings[2]->key);
        self::assertSame('parent-missing', $findings[2]->reason?->code);
        self::assertSame(['parentTable' => 'pages', 'parentUid' => 3], $findings[2]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_metadata', 3), $findings[3]->key);
        self::assertSame('parent-missing', $findings[3]->reason?->code);
        self::assertSame(['parentTable' => 'sys_file', 'parentUid' => 2], $findings[3]->reason->parameters);
        self::assertEquals(new RecordKey('sys_file_metadata', 4), $findings[4]->key);
        self::assertSame('parent-missing', $findings[4]->reason?->code);
        self::assertSame(['parentTable' => 'sys_file', 'parentUid' => 2], $findings[4]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 3), $findings[5]->key);
        self::assertSame('parent-missing', $findings[5]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[5]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 4), $findings[6]->key);
        self::assertSame('parent-missing', $findings[6]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[6]->reason->parameters);
    }

    #[Test]
    public function childOfMissingParentWithUidOneIsFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentMissingParentUidOneImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentMissing $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentMissing::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(1, $findings);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 1), $findings[0]->key);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentMissingTestImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentMissing $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentMissing::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentMissingTestFixed.csv');
    }
}
