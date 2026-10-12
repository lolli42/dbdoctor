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

class HealthCommandJsonTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

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
        self::assertSame('0.2', $report['schemaVersion']);
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
        self::assertSame(['live' => 1], $check['facets']['state']);
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
        self::assertSame(1, $context['page']['uid']);
        self::assertSame(1, $context['page']['rootline'][1]['uid']);
        self::assertSame('Site root', $context['page']['rootline'][1]['title']);
        self::assertNull($context['page']['rootPageId']);
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
        self::assertSame([], $check['findings']);
        self::assertTrue($check['truncated']);
        self::assertSame(6, $check['counts']['findings']);
        self::assertSame(6, array_sum($check['facets']['state']));
        self::assertArrayNotHasKey('tables', $report);
    }

    #[Test]
    public function reportsOkChecksInShortWithLimitZero(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--limit' => '0',
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
        self::assertSame('Site & root', $finding['context']['page']['rootline'][1]['title']);
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
