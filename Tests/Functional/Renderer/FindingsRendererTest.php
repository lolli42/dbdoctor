<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Renderer;

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
use Lolli\Dbdoctor\HealthCheck\TtContentPidMissing;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class FindingsRendererTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function rendersRecordsPerPageAndDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/FindingsRendererImport.csv');
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "p\nd\na\n");
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput();
        $this->get(HealthCheckRunner::class)->handle($this->get(TtContentPidMissing::class), new SymfonyStyle($input, $output), HealthCheckMode::Interactive, '');
        $display = $output->fetch();
        // Records per page: Two records on the missing page 3
        self::assertMatchesRegularExpression('/^  2 +\\[3\\|missing\\] *$/m', $display);
        // Details: Reason, missing pid, timestamp in UTC, missing language parent and source, live workspace
        self::assertMatchesRegularExpression(
            '/^  Page "3" does not exist +3 +\\[3\\|missing\\] +0 +2023-11-14 22:13 UTC +1 +\\[99\\|missing\\] +\\[98\\|missing\\] +\\[0\\]Live .* Not ok live translation on not existing page/m',
            $display
        );
        // Details: Timestamp 0 is kept, existing workspace with its title
        self::assertMatchesRegularExpression(
            '/^  Page "3" does not exist +4 +\\[3\\|missing\\] +0 +0 +0 +0 +0 +\\[1\\]Draft workspace .* Not ok workspace content on not existing page/m',
            $display
        );
    }
}
