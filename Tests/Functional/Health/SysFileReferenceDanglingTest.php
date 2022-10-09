<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Health;

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

use Lolli\Dbdoctor\Health\HealthInterface;
use Lolli\Dbdoctor\Health\SysFileReferenceDangling;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SysFileReferenceDanglingTest extends FunctionalTestCase
{
    use ProphecyTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    /**
     * @test
     */
    public function fixBrokenRecords(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceDanglingImport.csv');
        } else {
            $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceDanglingImportV11.csv');
        }
        $io = $this->prophesize(SymfonyStyle::class);
        $io->ask(Argument::cetera())->willReturn('e');
        /** @var SysFileReferenceDangling $subject */
        $subject = $this->get(SysFileReferenceDangling::class);
        $subject->handle($io->reveal(), HealthInterface::MODE_EXECUTE, '');
        $io->warning(Argument::cetera())->shouldHaveBeenCalled();
        $io->note(Argument::cetera())->shouldHaveBeenCalled();
        $io->text(Argument::cetera())->shouldHaveBeenCalled();
        if ((new Typo3Version())->getMajorVersion() >= 12) {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceDanglingFixed.csv');
        } else {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/SysFileReferenceDanglingFixedV11.csv');
        }
    }
}
