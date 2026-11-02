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
use Lolli\Dbdoctor\HealthCheck\PagesShortcutLoop;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Information\Typo3Version;

class PagesShortcutLoopTest extends AbstractFunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var PagesShortcutLoop $subject */
        $subject = $this->get(PagesShortcutLoop::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $this->get(HealthCheckRunner::class)->handle($subject, $io, HealthCheckMode::Interactive, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopImport.csv');
        /** @var PagesShortcutLoop $subject */
        $subject = $this->get(PagesShortcutLoop::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopFixed.csv');
    }

    #[Test]
    public function loopsAreFoundPerLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopTranslationImport.csv');
        /** @var PagesShortcutLoop $subject */
        $subject = $this->get(PagesShortcutLoop::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopTranslationFixed.csv');
    }

    #[Test]
    public function randomSubpageModeIsFollowedInCoreV14Only(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopRandomModeImport.csv');
        /** @var PagesShortcutLoop $subject */
        $subject = $this->get(PagesShortcutLoop::class);
        $this->get(HealthCheckRunner::class)->handle($subject, self::createStub(SymfonyStyle::class), HealthCheckMode::Execute, '');
        if ((new Typo3Version())->getMajorVersion() < 14) {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopRandomModeFixedV13.csv');
        } else {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/PagesShortcutLoopRandomModeFixedV14.csv');
        }
    }
}
