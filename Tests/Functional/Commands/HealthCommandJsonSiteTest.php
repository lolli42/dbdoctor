<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Commands;

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
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

/**
 * JSON report of an instance with a site: An own test instance, the site
 * configuration would affect other tests sharing an instance.
 */
class HealthCommandJsonSiteTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/dbdoctor/Tests/Functional/Fixtures/Sites/json-report/config.yaml' => 'typo3conf/sites/json-report/config.yaml',
    ];

    #[Test]
    public function reportsSiteFallbacksAndPageWithDoktypeAndTranslations(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--table' => 'tt_content',
            '--uid' => '5',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            [
                'rootPageId' => 1,
                'languages' => [
                    ['id' => 0],
                    ['id' => 1, 'fallbackType' => 'fallback', 'fallbacks' => [0]],
                    ['id' => 2, 'fallbackType' => 'strict', 'fallbacks' => []],
                ],
            ],
        ], $report['sites']);
        $finding = $report['checks'][0]['findings'][0];
        self::assertSame(2, $finding['pid']);
        // Sub page 1 with its translations in language 1 and 2, in site 1
        self::assertSame([
            'uid' => 2,
            'rootline' => [
                ['uid' => 0],
                ['uid' => 1, 'doktype' => 1],
                ['uid' => 2, 'doktype' => 1],
            ],
            'rootPageId' => 1,
            'translations' => [
                ['uid' => 3, 'language' => 1],
                ['uid' => 4, 'language' => 2],
            ],
        ], $report['pages'][2]);
        self::assertTrue($report['checks'][0]['groups'][0]['inSite']);
    }
}
