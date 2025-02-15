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
use PHPUnit\Framework\Attributes\Test;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\TcaTablesTranslatedLanguageParentDifferentPid;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TcaTablesTranslatedLanguageParentDifferentPidTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidFixed.csv');
    }

    #[Test]
    public function fixBrokenRecordsTtContentNotHiddenAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareFixed.csv');
    }

    #[Test]
    public function fixBrokenRecordsTtContentNotHiddenNotDeleteAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        unset($GLOBALS['TCA']['tt_content']['ctrl']['delete']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareImport.csv');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareFixed.csv');
    }
}
