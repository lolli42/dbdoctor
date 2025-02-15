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
use Lolli\Dbdoctor\HealthCheck\InlineForeignFieldChildrenParentDeleted;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class InlineForeignFieldChildrenParentDeletedTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        // Fixture uses ws fields, so it must be loaded
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentDeletedTestImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var InlineForeignFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldChildrenParentDeleted::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentDeletedTestImport.csv');
        /** @var InlineForeignFieldChildrenParentDeleted $subject */
        $subject = $this->get(InlineForeignFieldChildrenParentDeleted::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/InlineForeignFieldChildrenParentDeletedTestFixed.csv');
    }
}
