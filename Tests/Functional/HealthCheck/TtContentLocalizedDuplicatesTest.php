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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\TtContentLocalizedDuplicates;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TtContentLocalizedDuplicatesTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedDuplicatesImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TtContentLocalizedDuplicates $subject */
        $subject = $this->get(TtContentLocalizedDuplicates::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function findingsHaveReasons(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedDuplicatesImport.csv');
        /** @var TtContentLocalizedDuplicates $subject */
        $subject = $this->get(TtContentLocalizedDuplicates::class);
        $findings = $this->get(HealthCheckRunner::class)->inspect($subject)->findings;
        // Rows are fetched ordered by pid, the order of findings on the same pid depends on the DBMS
        self::assertEqualsCanonicalizing(
            [
                [new RecordKey('tt_content', 4), 'duplicate', ['kept' => 3], ['kept' => 3]],
                [new RecordKey('tt_content', 11), 'duplicate', ['kept' => 14], ['kept' => 14]],
                [new RecordKey('tt_content', 12), 'duplicate', ['kept' => 14], ['kept' => 14]],
                [new RecordKey('tt_content', 13), 'duplicate', ['kept' => 14], ['kept' => 14]],
                [new RecordKey('tt_content', 15), 'duplicate', ['kept' => 14], ['kept' => 14]],
                [new RecordKey('tt_content', 18), 'duplicate', ['kept' => 17], ['kept' => 17]],
                [new RecordKey('tt_content', 21), 'duplicate', ['kept' => 20], ['kept' => 20]],
                [new RecordKey('tt_content', 27), 'duplicate', ['kept' => 26], ['kept' => 26]],
            ],
            array_map(
                static fn(Finding $finding): array => [$finding->key, $finding->reason?->code, $finding->reason?->parameters, $finding->evidence],
                $findings
            )
        );
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedDuplicatesImport.csv');
        /** @var TtContentLocalizedDuplicates $subject */
        $subject = $this->get(TtContentLocalizedDuplicates::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TtContentLocalizedDuplicatesFixed.csv');
    }
}
