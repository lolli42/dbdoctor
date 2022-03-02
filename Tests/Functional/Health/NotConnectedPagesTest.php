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

use Lolli\Dbhealth\Health\NotConnectedPages;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class NotConnectedPagesTest extends FunctionalTestCase
{
    use ProphecyTrait;

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbhealth',
    ];

    /**
     * @test
     */
    public function findBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BrokenPagesRootlineImport.csv');
        $io = $this->prophesize(SymfonyStyle::class);
        $io->ask(Argument::cetera())->willReturn('a');
        /** @var NotConnectedPages $subject */
        $subject = $this->get(NotConnectedPages::class);
        $subject->process($io->reveal());
        $io->warning(['Found "4" record in table "pages" without connection to the tree root'])->shouldHaveBeenCalled();
    }

    /**
     * @test
     */
    public function deleteBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BrokenPagesRootlineImport.csv');
        $io = $this->prophesize(SymfonyStyle::class);
        $io->ask(Argument::cetera())->willReturn('y');
        /** @var NotConnectedPages $subject */
        $subject = $this->get(NotConnectedPages::class);
        $subject->process($io->reveal());
        $io->warning(Argument::cetera())->shouldHaveBeenCalled();
        $io->note(Argument::cetera())->shouldHaveBeenCalled();
        $io->success(Argument::cetera())->shouldHaveBeenCalled();
        $io->text('DELETE FROM "pages" WHERE "uid" = 5;')->shouldHaveBeenCalled();
        $io->text('DELETE FROM "pages" WHERE "uid" = 6;')->shouldHaveBeenCalled();
        $io->text('DELETE FROM "pages" WHERE "uid" = 8;')->shouldHaveBeenCalled();
        $io->text('DELETE FROM "pages" WHERE "uid" = 9;')->shouldHaveBeenCalled();
    }
}
