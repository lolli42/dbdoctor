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
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\WorkspacesNotLoadedRecordsDangling;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class WorkspacesNotLoadedRecordsDanglingWorkspacesLoadedTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesNotLoadedRecordsDanglingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var WorkspacesNotLoadedRecordsDangling $subject */
        $subject = $this->get(WorkspacesNotLoadedRecordsDangling::class);
        // No affected records: A single line, details are shown with -v only.
        $io->expects(self::once())->method('writeln')->with('<info>OK</info>  WorkspacesNotLoadedRecordsDangling');
        $io->expects(self::never())->method('success');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesNotLoadedRecordsDanglingImport.csv');
        /** @var WorkspacesNotLoadedRecordsDangling $subject */
        $subject = $this->get(WorkspacesNotLoadedRecordsDangling::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesNotLoadedRecordsDanglingWorkspacesLoadedFixed.csv');
    }
}
