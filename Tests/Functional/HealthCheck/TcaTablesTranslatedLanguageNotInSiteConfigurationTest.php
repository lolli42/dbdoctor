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
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\SiteWriter;

class TcaTablesTranslatedLanguageNotInSiteConfigurationTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Test instances are shared between test classes: Sites are written via SiteWriter, which
        // flushes the site configuration cache, and are removed again in tearDown().
        $this->writeSiteConfiguration(1, 'root-1', '/root-1/', [0, 1]);
        $this->writeSiteConfiguration(10, 'root-10', '/root-10/', [0, 2]);
    }

    protected function tearDown(): void
    {
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->delete('root-1');
        $siteWriter->delete('root-10');
        parent::tearDown();
    }

    #[Test]
    public function showDetails(): void
    {
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageNotInSiteConfigurationImport.csv');
        /** @var TcaTablesTranslatedLanguageNotInSiteConfiguration $subject */
        $subject = $this->get(TcaTablesTranslatedLanguageNotInSiteConfiguration::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslatedLanguageNotInSiteConfigurationFixed.csv');
    }

    /**
     * @param int[] $languageIds
     */
    private function writeSiteConfiguration(int $rootPageId, string $identifier, string $base, array $languageIds): void
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
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => $languages,
            'errorHandling' => [],
            'routes' => [],
        ]);
    }
}
