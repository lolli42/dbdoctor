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
use Lolli\Dbdoctor\HealthCheck\TcaTablesTranslatedLanguageNotInSiteConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TcaTablesTranslatedLanguageNotInSiteConfigurationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->addSiteConfiguration(1, 'root-1', '/', [0, 1]);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageNotInSiteConfigurationImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var TcaTablesTranslatedLanguageNotInSiteConfiguration $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageNotInSiteConfiguration::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->addSiteConfiguration(1, 'root-1', '/', [0, 1]);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageNotInSiteConfigurationImport.csv');
        /** @var TcaTablesTranslatedLanguageNotInSiteConfiguration $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageNotInSiteConfiguration::class);
        $subject->handle($this->createMock(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageNotInSiteConfigurationFixed.csv');
    }

    /**
     * @param int[] $languageIds
     */
    private function addSiteConfiguration(int $pageId, string $name, string $base, array $languageIds): void
    {
        $languages = [];
        foreach ($languageIds as $languageId) {
            $languages[] = [
                'title' => 'Language ' . $languageId,
                'enabled' => true,
                'languageId' => $languageId,
                'base' => $languageId === 0 ? '/' : '/lang-' . $languageId . '/',
                'locale' => 'en_US.UTF-8',
                'navigationTitle' => '',
                'flag' => 'us',
            ];
        }
        $configuration = [
            'rootPageId' => $pageId,
            'base' => $base,
            'languages' => $languages,
            'errorHandling' => [],
            'routes' => [],
        ];
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/' . $name . '/');
        $yamlFileContents = Yaml::dump($configuration, 99, 2);
        $fileName = $this->instancePath . '/typo3conf/sites/' . $name . '/config.yaml';
        GeneralUtility::writeFile($fileName, $yamlFileContents);
    }
}
