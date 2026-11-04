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
use Lolli\Dbdoctor\HealthCheck\InlineForeignFieldNoForeignTableFieldChildrenParentDeleted;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class InlineForeignFieldNoForeignTableFieldChildrenParentDeletedTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentDeletedTestImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentDeleted::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentDeletedTestImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentDeleted::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(6, $findings);
        // Sorted by uid, although found by two parent fields
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 2), $findings[0]->key);
        self::assertSame('parent-deleted', $findings[0]->reason?->code);
        self::assertSame(['parentTable' => 'pages', 'parentUid' => 2], $findings[0]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 4), $findings[1]->key);
        self::assertSame('parent-deleted', $findings[1]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[1]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_items', 5), $findings[2]->key);
        self::assertSame('parent-deleted', $findings[2]->reason?->code);
        self::assertSame(['parentTable' => 'pages', 'parentUid' => 3], $findings[2]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 4), $findings[3]->key);
        self::assertSame('parent-deleted', $findings[3]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[3]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 8), $findings[4]->key);
        self::assertSame('parent-deleted', $findings[4]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[4]->reason->parameters);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 9), $findings[5]->key);
        self::assertSame('parent-deleted', $findings[5]->reason?->code);
        self::assertSame(['parentTable' => 'tt_content', 'parentUid' => 2], $findings[5]->reason->parameters);
    }

    #[Test]
    public function childOfDeletedParentWithUidOneIsFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentDeletedParentUidOneImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentDeleted::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        self::assertCount(1, $findings);
        self::assertEquals(new RecordKey('tx_dbdoctortestsforeignfield_hotels', 1), $findings[0]->key);
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentDeletedTestImport.csv');
        /** @var InlineForeignFieldNoForeignTableFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldNoForeignTableFieldChildrenParentDeleted::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldNoForeignTableFieldChildrenParentDeletedTestFixed.csv');
    }
}
