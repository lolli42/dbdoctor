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
use PHPUnit\Framework\Attributes\Test;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\SysRedirectInvalidPid;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SysRedirectInvalidPidTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'redirects',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysRedirectInvalidPidImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var SysRedirectInvalidPid $subject */
        $subject = $this->get(SysRedirectInvalidPid::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->addSiteConfiguration(1, 'root-1', '/root-1/');
        $this->addSiteConfiguration(3, 'root-2', '/root-2/');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/SysRedirectInvalidPidImport.csv');
        /** @var SysRedirectInvalidPid $subject */
        $subject = $this->get(SysRedirectInvalidPid::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/SysRedirectInvalidPidFixed.csv');
    }

    private function addSiteConfiguration(int $pageId, string $name, string $base): void
    {
        $configuration = [
            'rootPageId' => $pageId,
            'base' => $base,
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => '',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ];
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/' . $name . '/');
        $yamlFileContents = Yaml::dump($configuration, 99, 2);
        $fileName = $this->instancePath . '/typo3conf/sites/' . $name . '/config.yaml';
        GeneralUtility::writeFile($fileName, $yamlFileContents);
    }
}
