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
use Lolli\Dbdoctor\HealthCheck\SysFileReferenceInvalidTableLocal;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SysFileReferenceInvalidTableLocalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    /**
     * @test
     */
    public function showDetails(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            self::markTestSkipped();
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceInvalidTableLocalImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var SysFileReferenceInvalidTableLocal $subject */
        $subject = $this->get(SysFileReferenceInvalidTableLocal::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('d', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    /**
     * @test
     */
    public function fixBrokenRecords(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            self::markTestSkipped();
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceInvalidTableLocalImport.csv');
        /** @var SysFileReferenceInvalidTableLocal $subject */
        $subject = $this->get(SysFileReferenceInvalidTableLocal::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceInvalidTableLocalFixed.csv');
    }
}
