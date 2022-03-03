<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Tests\Functional\Health;

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

use Lolli\Dbhealth\Health\TcaTablesWorkspaceRecordsDangling;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TcaTablesWorkspaceRecordsDanglingTest extends FunctionalTestCase
{
    use ProphecyTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbhealth',
    ];

    /**
     * @test
     */
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesWorkspaceRecordsDanglingImport.csv');
        $io = $this->prophesize(SymfonyStyle::class);
        $io->ask(Argument::cetera())->willReturn('y');
        /** @var TcaTablesWorkspaceRecordsDangling $subject */
        $subject = $this->get(TcaTablesWorkspaceRecordsDangling::class);
        $subject->process($io->reveal());
        $io->warning(Argument::cetera())->shouldHaveBeenCalled();
        $io->note(Argument::cetera())->shouldHaveBeenCalled();
        $io->success(Argument::cetera())->shouldHaveBeenCalled();
        $io->text(Argument::cetera())->shouldHaveBeenCalled();
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesWorkspaceRecordsDanglingFixed.csv');
    }
}
