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
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class HealthCommandJsonTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->instancePath . '/dbdoctor-report', true);
        @unlink($this->instancePath . '/dbdoctor-file');
        parent::tearDown();
    }

    #[Test]
    public function reportsFindingWithRecordAndDeleteChange(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--check' => ['TcaTablesPidMissing'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertSame('0.4', $report['schemaVersion']);
        self::assertSame(['check' => ['TcaTablesPidMissing']], $report['run']['options']['filter']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $report['run']['dbdoctor']);
        self::assertCount(1, $report['checks']);
        $check = $report['checks'][0];
        self::assertSame('TcaTablesPidMissing', $check['class']);
        self::assertSame('findings', $check['status']);
        self::assertSame(['remove'], $check['tags']);
        self::assertSame(['record'], $check['keyTypes']);
        self::assertSame(1, $check['counts']['findings']);
        self::assertSame(1, $check['counts']['writes']);
        // One of three be_users rows, far below the threshold
        self::assertSame(['findings' => 1, 'rows' => 1, 'share' => 0.33333, 'suspicious' => false], $check['counts']['byTable']['be_users']);
        self::assertSame(['type' => 'record', 'table' => 'be_users', 'uid' => 3], $check['findings'][0]['key']);
        self::assertSame(3, $check['findings'][0]['pid']);
        self::assertSame('page-missing', $check['findings'][0]['reason']['code']);
        self::assertSame(['pid' => 3], $check['findings'][0]['reason']['parameters']);
        self::assertSame(3, $check['findings'][0]['record']['uid']);
        self::assertSame(0, $check['findings'][0]['record']['deleted']);
        self::assertSame([['seq' => 1, 'operation' => 'delete', 'table' => 'be_users', 'uid' => 3]], $check['findings'][0]['writes']);
        self::assertSame(['delete' => 1], $check['facets']['operation']);
        self::assertSame(['page-missing' => 1], $check['facets']['reason']);
        self::assertSame(['live' => 1], $check['facets']['state']);
        // The fixture row has no tstamp
        self::assertSame(['none' => 1], $check['facets']['tstampAge']);
        self::assertSame(['live' => ['delete' => 1]], $check['facets']['stateByOperation']);
        self::assertSame([['operation' => 'delete', 'table' => 'be_users', 'count' => 1]], $check['facets']['writePatterns']);
        self::assertSame(3, $report['tables']['be_users']['rowCount']);
        self::assertSame('deleted', $report['tables']['be_users']['roles']['deleted']);
    }

    #[Test]
    public function reportsNoLabelsOfTablesWithPersonalData(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--tier' => 'labels',
            '--check' => ['TcaTablesPidMissing'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('labels', $report['checks'][0]['findings'][0]['record']);
    }

    #[Test]
    public function reportsOldAndNewValueOfUpdateChangeAndLabels(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--tier' => 'labels',
            '--limit' => '1',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertTrue($check['truncated']);
        self::assertCount(1, $check['findings']);
        self::assertSame(['type' => 'record', 'table' => 'pages', 'uid' => 4], $check['findings'][0]['key']);
        self::assertSame(['l10n_source' => ['old' => 0, 'new' => 2]], $check['findings'][0]['writes'][0]['set']);
        self::assertSame('update', $check['findings'][0]['writes'][0]['operation']);
        self::assertSame(2, $check['findings'][0]['record']['language']);
        self::assertSame(2, $check['findings'][0]['record']['l10nParent']);
        self::assertSame(['id' => 0, 'state' => 0, 'oid' => 0], $check['findings'][0]['record']['workspace']);
        self::assertSame(['title' => 'Not ok translated sub page 1'], $check['findings'][0]['record']['labels']);
        $context = $check['findings'][0]['context'];
        self::assertSame(2, $context['l10nParent']['uid']);
        self::assertSame(0, $context['l10nParent']['language']);
        self::assertSame(['title' => 'Sub page 1'], $context['l10nParent']['labels']);
        self::assertArrayNotHasKey('l10nSource', $context);
        self::assertArrayNotHasKey('evidence', $check['findings'][0]);
        // Rootlines are once per page in the report
        $page = $report['pages'][$check['findings'][0]['pid']];
        self::assertSame(1, $page['uid']);
        self::assertSame(1, $page['rootline'][1]['uid']);
        self::assertSame('Site root', $page['rootline'][1]['title']);
        self::assertNull($page['rootPageId']);
        self::assertSame(['none' => $check['counts']['findings']], $check['facets']['rootPage']);
        // Facets count all findings, not only those up to the limit
        self::assertContains(
            ['operation' => 'update', 'table' => 'pages', 'field' => 'l10n_source', 'value' => 'role:l10nParent', 'count' => 1],
            $check['facets']['writePatterns']
        );
        self::assertSame($check['counts']['findings'], array_sum($check['facets']['state']));
    }

    #[Test]
    public function reportsCountsAndFacetsWithoutFindingsWithLimitZero(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => '0',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertSame(0, $report['run']['options']['limit']);
        self::assertArrayNotHasKey('findings', $check);
        self::assertArrayNotHasKey('truncated', $check);
        self::assertSame(6, $check['counts']['findings']);
        self::assertSame(6, array_sum($check['facets']['state']));
        self::assertArrayNotHasKey('tables', $report);
    }

    #[Test]
    public function reportsOkChecksInShort(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--check' => ['TcaTablesPidMissing'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['position', 'class', 'status', 'preliminary'], array_keys($report['checks'][0]));
        self::assertSame('ok', $report['checks'][0]['status']);
    }

    #[Test]
    public function reportsAllFindingsWithLimitAll(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => 'all',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertSame('all', $report['run']['options']['limit']);
        self::assertCount(6, $check['findings']);
        self::assertFalse($check['truncated']);
    }

    #[Test]
    public function reportsLabelsWithoutTagsAndShortened(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('pages')->update('pages', ['title' => '<b>Site</b> &amp;  root'], ['uid' => 1]);
        $connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['bodytext' => '<p>' . str_repeat('Lorem&nbsp;ipsum ', 10) . '</p>'], ['uid' => 5]);
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--tier' => 'labels',
            '--table' => 'tt_content',
            '--uid' => '5',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $finding = $report['checks'][0]['findings'][0];
        self::assertSame(['tagsStripped' => true, 'maxLength' => 80], $report['run']['options']['labels']);
        self::assertSame(substr(str_repeat('Lorem ipsum ', 10), 0, 79) . '…', $finding['record']['labels']['bodytext']);
        self::assertSame('Site & root', $report['pages'][$finding['pid']]['rootline'][1]['title']);
    }

    #[Test]
    public function reportsLabelsWithoutNullFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['bodytext' => null], ['uid' => 5]);
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--tier' => 'labels',
            '--table' => 'tt_content',
            '--uid' => '5',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $labels = $report['checks'][0]['findings'][0]['record']['labels'];
        self::assertArrayHasKey('header', $labels);
        self::assertArrayNotHasKey('bodytext', $labels);
    }

    #[Test]
    public function reportsCompactJson(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--compact' => true,
            '--check' => ['TcaTablesPidMissing'],
        ]);
        $display = $commandTester->getDisplay();
        self::assertStringNotContainsString("\n", trim($display));
        $report = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('findings', $report['checks'][0]['status']);
    }

    #[Test]
    public function doesNotRunChecksAfterFirstCheckWithFindings(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--check' => ['TcaTablesPidMissing', 'TcaTablesPidDeleted'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('findings', $report['checks'][0]['status']);
        self::assertFalse($report['checks'][0]['preliminary']);
        self::assertSame('TcaTablesPidDeleted', $report['checks'][1]['class']);
        self::assertSame('not-run', $report['checks'][1]['status']);
    }

    #[Test]
    public function marksChecksAfterFirstCheckWithFindingsAsPreliminaryAndSkipsChecksRelyingOnFixes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--check' => ['TcaTablesPidDeleted'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        // be_users 3 is on a missing page, which TcaTablesPidMissing finds: This check relies on it being fixed
        self::assertSame('skipped', $report['checks'][0]['status']);
        self::assertStringContainsString('but that page does not exist', $report['checks'][0]['skipReason']);
        self::assertTrue($report['checks'][0]['preliminary']);
        self::assertSame(['position', 'class', 'status', 'preliminary', 'skipReason'], array_keys($report['checks'][0]));
    }

    #[Test]
    public function reportsErrorWithoutCheckMode(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--format' => 'json',
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertSame('Option "--format json" is available with "--mode check" only.', $report['error']['message']);
    }

    #[Test]
    public function filtersFindingsByTable(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--table' => 'pages',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertSame(1, $check['counts']['findings']);
        self::assertGreaterThan(1, $check['counts']['total']);
        self::assertSame(['type' => 'record', 'table' => 'pages', 'uid' => 4], $check['findings'][0]['key']);
        self::assertSame(['table' => 'pages', 'check' => ['TcaTablesTranslationSourceSetWithParent']], $report['run']['options']['filter']);
    }

    #[Test]
    public function filtersFindingsByPid(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--pid' => '1',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertTrue($check['filterMatches']);
        self::assertSame(1, $check['counts']['findings']);
        self::assertSame(['type' => 'record', 'table' => 'pages', 'uid' => 4], $check['findings'][0]['key']);
    }

    #[Test]
    public function reportsCheckWithoutFindingsMatchingTheFilterInShort(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--pid' => '3',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertSame('findings', $check['status']);
        self::assertFalse($check['filterMatches']);
        self::assertSame(['findings' => 0, 'total' => 6], $check['counts']);
        self::assertArrayNotHasKey('facets', $check);
        self::assertArrayNotHasKey('findings', $check);
    }

    #[Test]
    public function filtersFindingsBySubtree(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--subtree' => '2',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        // Records on sub page 2, not the page translation on its parent page 1
        self::assertSame(['tt_content'], array_keys($check['counts']['byTable']));
        self::assertSame($check['counts']['findings'], $check['counts']['byTable']['tt_content']['findings']);
    }

    #[Test]
    public function writesIndexCheckFileAndAllFindingsAsJsonLinesToOutputDirectory(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $directory = $this->instancePath . '/dbdoctor-report';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
            '--output' => $directory,
        ]);
        $stdout = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['schemaVersion' => '0.4', 'index' => $directory . '/index.json', 'exitCode' => $result], $stdout);
        $index = json_decode((string)file_get_contents($directory . '/index.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('all', $index['run']['options']['limit']);
        self::assertArrayNotHasKey('tables', $index);
        $entry = $index['checks'][0];
        self::assertSame('TcaTablesTranslationSourceSetWithParent', $entry['class']);
        self::assertSame('findings', $entry['status']);
        self::assertSame(['findings', 'records', 'writes', 'total'], array_keys($entry['counts']));
        self::assertFalse($entry['truncated']);
        $checkFile = json_decode((string)file_get_contents($directory . '/' . $entry['file']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.4', $checkFile['schemaVersion']);
        self::assertArrayNotHasKey('findings', $checkFile['check']);
        self::assertSame($entry['counts']['findings'], array_sum($checkFile['check']['facets']['state']));
        self::assertSame($entry['counts']['findings'], array_sum(array_column($checkFile['check']['groups'], 'count')));
        $lines = (array)file($directory . '/' . $entry['findingsFile'], FILE_IGNORE_NEW_LINES);
        self::assertCount($entry['counts']['findings'], $lines);
        $finding = json_decode((string)$lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'record', 'table' => 'pages', 'uid' => 4], $finding['key']);
        self::assertSame(['l10n_source' => ['old' => 0, 'new' => 2]], $finding['writes'][0]['set']);
        // Tables and pages are files of the report, one line each
        self::assertSame('tables.jsonl', $index['tablesFile']);
        $tables = array_map(static fn(string|false $line): array => json_decode((string)$line, true, 512, JSON_THROW_ON_ERROR), (array)file($directory . '/tables.jsonl', FILE_IGNORE_NEW_LINES));
        self::assertSame(['pages', 'tt_content'], array_column($tables, 'table'));
        self::assertSame('sys_language_uid', $tables[0]['roles']['language']);
        self::assertSame('pages.jsonl', $index['pagesFile']);
        $pages = array_map(static fn(string|false $line): array => json_decode((string)$line, true, 512, JSON_THROW_ON_ERROR), (array)file($directory . '/pages.jsonl', FILE_IGNORE_NEW_LINES));
        self::assertSame([1, 2], array_column($pages, 'uid'));
    }

    #[Test]
    public function writesFindingsUpToGivenLimitToOutputDirectory(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $directory = $this->instancePath . '/dbdoctor-report';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => '1',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
            '--output' => $directory,
        ]);
        $index = json_decode((string)file_get_contents($directory . '/index.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($index['checks'][0]['truncated']);
        self::assertCount(1, (array)file($directory . '/' . $index['checks'][0]['findingsFile']));
    }

    #[Test]
    public function writesNoFindingsFileWithLimitZeroToOutputDirectory(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $directory = $this->instancePath . '/dbdoctor-report';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => '0',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
            '--output' => $directory,
        ]);
        $index = json_decode((string)file_get_contents($directory . '/index.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('findingsFile', $index['checks'][0]);
        self::assertSame([basename($index['checks'][0]['file'])], array_values(array_diff(scandir($directory . '/checks'), ['.', '..'])));
    }

    #[Test]
    public function reportsErrorIfOutputDirectoryExists(): void
    {
        $directory = $this->instancePath . '/dbdoctor-report';
        GeneralUtility::mkdir_deep($directory);
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--output' => $directory,
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertSame('Output directory "' . $directory . '" exists already.', $report['error']['message']);
    }

    #[Test]
    public function reportsErrorIfOutputDirectoryCanNotBeCreated(): void
    {
        // A directory below a file can not be created
        $directory = $this->instancePath . '/dbdoctor-file/report';
        file_put_contents($this->instancePath . '/dbdoctor-file', '');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--output' => $directory,
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertSame('Output directory "' . $directory . '" can not be created.', $report['error']['message']);
    }

    #[Test]
    public function reportsGroupsWithSamplesOfFindingsInTheResult(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesTranslationSourceSetWithParentImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => '1',
            '--check' => ['TcaTablesTranslationSourceSetWithParent'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        // Largest group first. pages 4 is the first finding, the tt_content findings are cut by
        // the limit: Their groups have no samples.
        self::assertSame([
            [
                'table' => 'tt_content',
                'reason' => 'none',
                'state' => 'workspace',
                'hidden' => 'visible',
                'operation' => 'update',
                'inSite' => false,
                'count' => 4,
                'samples' => [],
            ],
            [
                'table' => 'pages',
                'reason' => 'none',
                'state' => 'live',
                'hidden' => 'visible',
                'operation' => 'update',
                'inSite' => false,
                'count' => 1,
                'samples' => [$check['findings'][0]['id']],
            ],
            [
                'table' => 'tt_content',
                'reason' => 'none',
                'state' => 'live',
                'hidden' => 'visible',
                'operation' => 'update',
                'inSite' => false,
                'count' => 1,
                'samples' => [],
            ],
        ], $check['groups']);
    }

    #[Test]
    public function reportsErrorForUidWithoutTable(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--uid' => '4',
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertSame('Option "--uid" needs "--table".', $report['error']['message']);
    }
}
