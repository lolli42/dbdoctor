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
use Lolli\Dbdoctor\HealthCheck\SysFileMetadataMissing;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SysFileMetadataMissingTest extends AbstractFunctionalTestCase
{
    /**
     * A 3x2 pixels png image.
     */
    private const IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAEElEQVR4nGP4z8AAQQxwFgBB0gX7h/C5SAAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/');
        GeneralUtility::writeFile($this->instancePath . '/fileadmin/dbdoctor-image.png', (string)base64_decode(self::IMAGE));
    }

    protected function tearDown(): void
    {
        @unlink($this->instancePath . '/fileadmin/dbdoctor-image.png');
        parent::tearDown();
    }

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileMetadataMissingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var SysFileMetadataMissing $subject */
        $subject = $this->get(SysFileMetadataMissing::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysFileMetadataMissingImport.csv');
        /** @var SysFileMetadataMissing $subject */
        $subject = $this->get(SysFileMetadataMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/SysFileMetadataMissingFixed.csv');
    }
}
