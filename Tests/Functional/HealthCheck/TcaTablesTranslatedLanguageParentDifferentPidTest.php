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

use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\TcaTablesTranslatedLanguageParentDifferentPid;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TcaTablesTranslatedLanguageParentDifferentPidTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    /**
     * @test
     */
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidImport.csv');
        $io = $this->getMockBuilder(SymfonyStyle::class)->disableOriginalConstructor()->getMock();
        $io->expects(self::atLeastOnce())->method('warning');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($io, HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidFixed.csv');
    }

    /**
     * @test
     */
    public function fixBrokenRecordsTtContentNotHiddenAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareImport.csv');
        $io = $this->getMockBuilder(SymfonyStyle::class)->disableOriginalConstructor()->getMock();
        $io->expects(self::atLeastOnce())->method('warning');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($io, HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenAwareFixed.csv');
    }

    /**
     * @test
     */
    public function fixBrokenRecordsTtContentNotHiddenNotDeleteAware(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled']);
        unset($GLOBALS['TCA']['tt_content']['ctrl']['delete']);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareImport.csv');
        $io = $this->getMockBuilder(SymfonyStyle::class)->disableOriginalConstructor()->getMock();
        $io->expects(self::atLeastOnce())->method('warning');
        /** @var TcaTablesTranslatedLanguageParentDifferentPid $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageParentDifferentPid::class);
        $subject->handle($io, HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageParentDifferentPidTtContentNotHiddenNotDeleteAwareFixed.csv');
    }
}
