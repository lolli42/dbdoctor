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
use Lolli\Dbdoctor\HealthCheck\TcaTablesPidMissing;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

class HealthCommandTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        @unlink($this->instancePath . '/dbdoctor-file');
        parent::tearDown();
    }

    /**
     * Display with whitespace collapsed: SymfonyStyle wraps long messages into blocks.
     */
    private function getNormalizedDisplay(CommandTester $commandTester): string
    {
        return (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay());
    }

    #[Test]
    public function reportsErrorForInvalidFormat(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--format' => 'xml']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid format "xml".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForInvalidMode(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'fix']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid mode "fix".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForFileWithCheckMode(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'check', '--file' => $file]);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Option "--file" not available with "--mode check"', $this->getNormalizedDisplay($commandTester));
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function reportsErrorForRelativeFile(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => 'dbdoctor-file']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Invalid file "dbdoctor-file". Must be an absolute path.', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function reportsErrorForExistingFileAndKeepsIt(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        file_put_contents($file, 'existing content');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('File exists already.', $this->getNormalizedDisplay($commandTester));
        self::assertSame('existing content', file_get_contents($file));
    }

    #[Test]
    public function reportsErrorForExecuteModeWithoutFile(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'execute']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Option "--file" with valid file path is mandatory when using "--mode execute".', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function checkModeReturnsOkWithoutFindings(): void
    {
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'check']);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        self::assertStringContainsString('OK  TcaTablesPidMissing', $commandTester->getDisplay());
        self::assertStringNotContainsString('referenceindex:update', $this->getNormalizedDisplay($commandTester));
    }

    #[Test]
    public function checkModeReturnsBrokenWithFindingsAndChangesNothing(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'check']);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertStringNotContainsString('OK  TcaTablesPidMissing', $commandTester->getDisplay());
        self::assertStringNotContainsString('referenceindex:update', $this->getNormalizedDisplay($commandTester));
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
    }

    #[Test]
    public function executeModeFixesRecordsAndWritesFile(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertStringContainsString('referenceindex:update', $this->getNormalizedDisplay($commandTester));
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingFixed.csv');
        // SQL quoting differs per DBMS, the header line is checked exactly.
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertSame('# Triggered by ' . TcaTablesPidMissing::class, $lines[0]);
    }

    #[Test]
    public function executeModeRemovesEmptyFileWithoutFindings(): void
    {
        $file = $this->instancePath . '/dbdoctor-file';
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'execute', '--file' => $file]);
        self::assertSame(HealthCheckResult::Ok->value, $result);
        self::assertStringNotContainsString('referenceindex:update', $this->getNormalizedDisplay($commandTester));
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function interactiveModeShowsHelpReloadsAndAborts(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->setInputs(['?', 'r', 'a']);
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Abort->value, $result);
        $display = $this->getNormalizedDisplay($commandTester);
        self::assertStringContainsString('s - SIMULATE suggested changes, no execution', $display);
        self::assertStringContainsString('Aborting ...', $display);
        self::assertStringNotContainsString('referenceindex:update', $display);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
    }

    #[Test]
    public function interactiveModeExecutesChanges(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->setInputs(['e']);
        $result = $commandTester->execute([]);
        self::assertSame(HealthCheckResult::Broken->value, $result);
        self::assertStringContainsString('referenceindex:update', $this->getNormalizedDisplay($commandTester));
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TcaTablesPidMissingFixed.csv');
    }

    #[Test]
    public function reportsErrorIfDatabaseSchemaIsNotInSyncAndRunsNoCheck(): void
    {
        // A column defined but missing in the database
        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'health-command-test-schema',
            static function (AlterTableDefinitionStatementsEvent $event): void {
                $event->addSqlData('CREATE TABLE pages (some_field VARCHAR(10) DEFAULT \'\' NOT NULL);');
            }
        );
        $container->get(ListenerProvider::class)->addListener(AlterTableDefinitionStatementsEvent::class, 'health-command-test-schema');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $result = $commandTester->execute(['--mode' => 'check']);
        self::assertSame(HealthCheckResult::Error->value, $result);
        self::assertStringContainsString('Current database schema is not in sync with TCA', $this->getNormalizedDisplay($commandTester));
        self::assertStringNotContainsString('OK  ', $commandTester->getDisplay());
    }
}
