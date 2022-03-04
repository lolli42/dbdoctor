<?php

declare(strict_types=1);

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

namespace Lolli\Dbdoctor\Tests\Acceptance\Cli;

use Lolli\Dbdoctor\Tests\Acceptance\Support\CliTester;

class DbdoctorCommandCest
{
    protected string $command = '../../../../../bin/typo3 dbdoctor:health ';

    /**
     * @param CliTester $I
     */
    public function runDbdoctorCommand(CliTester $I): void
    {
        $I->amGoingTo('Call bin/typo3 dbdoctor:health');
        $I->runShellCommand($this->command);
        $I->seeInShellOutput('Find and optionally fix database inconsistencies');
    }
}
