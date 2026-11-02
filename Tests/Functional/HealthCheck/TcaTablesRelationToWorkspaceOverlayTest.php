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
use Lolli\Dbdoctor\HealthCheck\TcaTablesRelationToWorkspaceOverlay;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class TcaTablesRelationToWorkspaceOverlayTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesRelationToWorkspaceOverlay $subject */
        $subject = $this->get(TcaTablesRelationToWorkspaceOverlay::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayImport.csv');
        /** @var TcaTablesRelationToWorkspaceOverlay $subject */
        $subject = $this->get(TcaTablesRelationToWorkspaceOverlay::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayFixed.csv');
    }

    #[Test]
    public function mmRowsOfLiveRecordsPointingToOverlaysAreRemoved(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayMmImport.csv');
        /** @var TcaTablesRelationToWorkspaceOverlay $subject */
        $subject = $this->get(TcaTablesRelationToWorkspaceOverlay::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayMmFixed.csv');
    }

    #[Test]
    public function liveInlineChildrenOfOverlaysAreRemoved(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayInlineImport.csv');
        /** @var TcaTablesRelationToWorkspaceOverlay $subject */
        $subject = $this->get(TcaTablesRelationToWorkspaceOverlay::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesRelationToWorkspaceOverlayInlineFixed.csv');
    }
}
