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
use Lolli\Dbdoctor\HealthCheck\TcaTablesPidDeleted;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TcaTablesPidDeletedTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'impexp',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    /**
     * @test
     */
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidDeletedImport.csv');
        $io = $this->getMockBuilder(SymfonyStyle::class)->disableOriginalConstructor()->getMock();
        $io->expects(self::atLeastOnce())->method('warning');
        /** @var TcaTablesPidDeleted $subject */
        $subject = $this->get(TcaTablesPidDeleted::class);
        $subject->handle($io, HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidDeletedFixed.csv');
    }
}
