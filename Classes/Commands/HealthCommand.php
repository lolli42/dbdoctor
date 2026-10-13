<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Commands;

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

use Lolli\Dbdoctor\DatabaseSchema\DatabaseSchemaChecker;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInspection;
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthCheck\InspectionStatus;
use Lolli\Dbdoctor\HealthFactory\HealthFactoryInterface;
use Lolli\Dbdoctor\StructuredOutput\JsonReportBuilder;
use Lolli\Dbdoctor\StructuredOutput\ReportContext;
use Lolli\Dbdoctor\StructuredOutput\ReportOptions;
use Lolli\Dbdoctor\StructuredOutput\ReportScope;
use Lolli\Dbdoctor\StructuredOutput\ReportTier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Main CLI entry point
 */
final class HealthCommand extends Command
{
    public function __construct(
        private readonly HealthFactoryInterface $healthFactory,
        private readonly DatabaseSchemaChecker $databaseSchemaChecker,
        private readonly HealthCheckRunner $healthCheckRunner,
        private readonly JsonReportBuilder $jsonReportBuilder,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_OPTIONAL,
            'interactive|check|execute - check: run all checks but no DB changes, execute: blindly execute all changes (!)',
            'interactive'
        );
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_OPTIONAL,
            'log execute queries to a given absolute file'
        );
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'text|json - json (experimental): structured, read-only report on stdout, with --mode check only',
            'text'
        );
        $this->addOption(
            'scope',
            null,
            InputOption::VALUE_REQUIRED,
            ReportScope::UntilFirstFinding->value . '|' . ReportScope::All->value . ' - json only: run checks until the first'
            . ' one that is not ok, or all checks with results after it marked as preliminary',
            ReportScope::UntilFirstFinding->value
        );
        $this->addOption(
            'tier',
            null,
            InputOption::VALUE_REQUIRED,
            ReportTier::Structure->value . '|' . ReportTier::Labels->value . ' - json only: record data in the report,'
            . ' labels add TCA label fields except of tables with personal data',
            ReportTier::Structure->value
        );
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: maximum number of findings per check, 0 for none (summary), "all" for all, counts and facets are always complete',
            '100'
        );
        $this->addOption(
            'context',
            null,
            InputOption::VALUE_REQUIRED,
            ReportContext::Basic->value . '|' . ReportContext::None->value . ' - json only: related records of findings,'
            . ' basic adds rootline, language parent and translation source',
            ReportContext::Basic->value
        );
        $this->addOption(
            'table',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: report only findings of this table, the record table or the MM table'
        );
        $this->addOption(
            'uid',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: report only findings of this record uid, with --table'
        );
        $this->addOption(
            'pid',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: report only findings of records on this page'
        );
        $this->addOption(
            'subtree',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: report only findings of records on this page and its sub pages'
        );
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'json only: report only this check, class name with or without namespace, repeatable.'
            . ' Earlier checks still run to tell if the result is preliminary'
        );
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'json only: write the report into this new directory instead of stdout: index.json, and per check with'
            . ' findings a check file and all its findings as JSON Lines. --limit is "all" unless given'
        );
        $this->addOption(
            'compact',
            null,
            InputOption::VALUE_NONE,
            'json only: JSON without whitespace, about half the size, for tools and agents'
        );
        $this->setHelp('This is an interactive command to go through a list of database health checks finding inconsistencies.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((string)$input->getOption('format') === 'json') {
            return $this->executeJson($input, $output);
        }
        if ((string)$input->getOption('format') !== 'text') {
            (new SymfonyStyle($input, $output))->error('Invalid format "' . $input->getOption('format') . '". Use -h for help on valid options.');
            return HealthCheckResult::Error->value;
        }
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $modeOption = (string)$input->getOption('mode');
        $mode = match ($modeOption) {
            'interactive' => HealthCheckMode::Interactive,
            'check' => HealthCheckMode::Check,
            'execute' => HealthCheckMode::Execute,
            default => null,
        };
        if ($mode === null) {
            $io->error('Invalid mode "' . $modeOption . '". Use -h for help on valid options.');
            return HealthCheckResult::Error->value;
        }

        $file = (string)$input->getOption('file');
        if ($file && $mode === HealthCheckMode::Check) {
            $io->error('Option "--file" not available with "--mode check"');
            return HealthCheckResult::Error->value;
        }
        if ($file) {
            if (!PathUtility::isAbsolutePath($file)) {
                $io->error('Invalid file "' . $file . '". Must be an absolute path.');
                return HealthCheckResult::Error->value;
            }
            if (file_exists($file)) {
                $io->error('Invalid file "' . $file . '". File exists already.');
                return HealthCheckResult::Error->value;
            }
            $touchFile = file_put_contents($file, '');
            if ($touchFile === false) {
                $io->error('Invalid file "' . $file . '". Unable to create.');
                return HealthCheckResult::Error->value;
            }
        }

        if ($mode === HealthCheckMode::Execute && empty($file)) {
            $io->error(
                'Option "--file" with valid file path is mandatory when using "--mode execute". '
                . 'The file must be an absolute path and the file must not exist yet. This means '
                . 'you need some unique filename when executed, for instance by having some date '
                . 'within the filename.'
            );
            return HealthCheckResult::Error->value;
        }

        if (!$this->checkDatabaseSchema($io)) {
            return HealthCheckResult::Error->value;
        }

        // Exit code: Bits of the results of all checks
        $exitCode = HealthCheckResult::Ok->value;
        foreach ($this->healthFactory->getNext() as $healthInstance) {
            $result = $this->healthCheckRunner->handle($healthInstance, $io, $mode, $file);
            $exitCode |= $result->value;
            if ($result === HealthCheckResult::Abort) {
                $io->warning('Aborting ...');
                $exitCode |= $this->removeEmptyFile($io, $file)->value;
                $this->outputSysRefIndexWarning($io, $mode, $exitCode);
                return $exitCode;
            }
        }
        $exitCode |= $this->removeEmptyFile($io, $file)->value;
        $this->outputSysRefIndexWarning($io, $mode, $exitCode);
        return $exitCode;
    }

    /**
     * Read-only structured report: Only JSON on stdout, the exit code as in check mode.
     */
    private function executeJson(InputInterface $input, OutputInterface $output): int
    {
        $start = hrtime(true);
        $scopeOption = (string)$input->getOption('scope');
        $tierOption = (string)$input->getOption('tier');
        $outputDirectory = $input->getOption('output') === null ? null : (string)$input->getOption('output');
        // A directory gets all findings, stdout the default limit
        $limit = $outputDirectory !== null && !$input->hasParameterOption('--limit') ? 'all' : (string)$input->getOption('limit');
        if ((string)$input->getOption('mode') !== 'check') {
            return $this->outputJsonError($input, $output, 'Option "--format json" is available with "--mode check" only.');
        }
        $scope = ReportScope::tryFrom($scopeOption);
        if ($scope === null) {
            return $this->outputJsonError($input, $output, 'Invalid scope "' . $scopeOption . '".');
        }
        $tier = ReportTier::tryFrom($tierOption);
        if ($tier === null) {
            return $this->outputJsonError($input, $output, 'Invalid tier "' . $tierOption . '".');
        }
        $contextOption = (string)$input->getOption('context');
        $context = ReportContext::tryFrom($contextOption);
        if ($context === null) {
            return $this->outputJsonError($input, $output, 'Invalid context "' . $contextOption . '".');
        }
        if ($limit !== 'all' && !ctype_digit($limit)) {
            return $this->outputJsonError($input, $output, 'Invalid limit "' . $limit . '".');
        }
        if ($this->databaseSchemaChecker->hasIncompleteTablesColumnsIndexes()) {
            return $this->outputJsonError(
                $input,
                $output,
                'Current database schema is not in sync with TCA: Missing tables, missing or not adapted columns or indexes were detected.'
            );
        }
        $filters = [];
        foreach (['uid', 'pid', 'subtree'] as $filterName) {
            $filterValue = $input->getOption($filterName);
            if ($filterValue !== null && !ctype_digit((string)$filterValue)) {
                return $this->outputJsonError($input, $output, 'Invalid ' . $filterName . ' "' . $filterValue . '".');
            }
            $filters[$filterName] = $filterValue === null ? null : (int)$filterValue;
        }
        $filterTable = $input->getOption('table') === null ? null : (string)$input->getOption('table');
        if ($filters['uid'] !== null && $filterTable === null) {
            return $this->outputJsonError($input, $output, 'Option "--uid" needs "--table".');
        }
        /** @var list<string> $onlyChecks */
        $onlyChecks = array_values((array)$input->getOption('check'));
        $options = new ReportOptions($scope, $tier, $limit === 'all' ? null : (int)$limit, $context, $filterTable, $filters['uid'], $filters['pid'], $filters['subtree'], $onlyChecks);
        if ($outputDirectory !== null) {
            if (!PathUtility::isAbsolutePath($outputDirectory)) {
                $outputDirectory = getcwd() . '/' . $outputDirectory;
            }
            $outputDirectory = rtrim($outputDirectory, '/');
            if (file_exists($outputDirectory)) {
                return $this->outputJsonError($input, $output, 'Output directory "' . $outputDirectory . '" exists already.');
            }
            try {
                GeneralUtility::mkdir_deep($outputDirectory . '/checks');
            } catch (\RuntimeException) {
                return $this->outputJsonError($input, $output, 'Output directory "' . $outputDirectory . '" can not be created.');
            }
        }

        $result = HealthCheckResult::Ok->value;
        $checks = [];
        $tableNames = [];
        $pages = [];
        $position = 0;
        $isNotOkSeen = false;
        foreach ($this->healthFactory->getNext() as $healthInstance) {
            $position++;
            $isReported = $onlyChecks === []
                || in_array($healthInstance::class, $onlyChecks, true)
                || in_array((new \ReflectionClass($healthInstance))->getShortName(), $onlyChecks, true);
            if ($isNotOkSeen && $options->scope === ReportScope::UntilFirstFinding) {
                if (!$isReported) {
                    continue;
                }
                $checks[] = $this->jsonReportBuilder->buildCheckWithoutInspection($position, $healthInstance::class, 'not-run');
                continue;
            }
            $inspection = $this->healthCheckRunner->inspect($healthInstance);
            if ($isReported) {
                $checkResult = $outputDirectory === null
                    ? $this->jsonReportBuilder->buildCheck($position, $healthInstance::class, $inspection, $isNotOkSeen, $options)
                    : $this->writeCheckFiles($input, $outputDirectory, $position, $healthInstance::class, $inspection, $isNotOkSeen, $options);
                $checks[] = $checkResult['check'];
                $tableNames = array_merge($tableNames, $checkResult['tables']);
                // Rootlines are the same for all checks of a run, the first one is kept
                $pages += $checkResult['pages'];
            }
            if ($inspection->status !== InspectionStatus::Ok) {
                $isNotOkSeen = true;
                $result = HealthCheckResult::Broken->value;
            }
        }
        $tables = $this->jsonReportBuilder->buildTables(array_values(array_unique($tableNames)));
        ksort($pages);
        $report = [
            'schemaVersion' => JsonReportBuilder::SCHEMA_VERSION,
            'run' => $this->jsonReportBuilder->buildRun($options, (int)((hrtime(true) - $start) / 1000000), $result),
            'sites' => $this->jsonReportBuilder->buildSites($options),
            'tables' => (object)$tables,
            'pages' => (object)$pages,
            'checks' => $checks,
        ];
        if ($options->isSummary()) {
            // TCA roles, row counts and inline parents, and rootlines help to judge records, a summary has none
            unset($report['tables'], $report['pages']);
        }
        if ($outputDirectory !== null) {
            // Tables and pages are files of their own with one entry per line, they can be large
            unset($report['tables'], $report['pages']);
            if (!$options->isSummary()) {
                $report['tablesFile'] = 'tables.jsonl';
                $report['pagesFile'] = 'pages.jsonl';
                $this->writeJsonLines($outputDirectory . '/tables.jsonl', array_map(
                    static fn(string $tableName, array $table): array => ['table' => $tableName] + $table,
                    array_keys($tables),
                    $tables
                ));
                $this->writeJsonLines($outputDirectory . '/pages.jsonl', array_values($pages));
            }
            file_put_contents($outputDirectory . '/index.json', $this->encodeJson($input, $report) . "\n");
            $output->writeln($this->encodeJson($input, [
                'schemaVersion' => JsonReportBuilder::SCHEMA_VERSION,
                'index' => $outputDirectory . '/index.json',
                'exitCode' => $result,
            ]));
            return $result;
        }
        $output->writeln($this->encodeJson($input, $report));
        return $result;
    }

    /**
     * Report of a check with findings in a check file, its findings in a JSON Lines file, one
     * finding per line, written while they are built. Returns the entry of the check in the index
     * as check, and its tables and pages.
     *
     * @param class-string $className
     * @return array{check: array<string, mixed>, tables: list<string>, pages: array<int, array<string, mixed>>}
     */
    private function writeCheckFiles(InputInterface $input, string $outputDirectory, int $position, string $className, HealthCheckInspection $inspection, bool $preliminary, ReportOptions $options): array
    {
        $baseName = 'checks/' . sprintf('%02d', $position) . '-' . (new \ReflectionClass($className))->getShortName();
        $findingsHandle = null;
        $findingWriter = null;
        if ($inspection->status === InspectionStatus::Findings && !$options->isSummary()) {
            $findingsHandle = fopen($outputDirectory . '/' . $baseName . '.findings.jsonl', 'w');
            if ($findingsHandle === false) {
                throw new \RuntimeException('Unable to create file "' . $outputDirectory . '/' . $baseName . '.findings.jsonl".', 1791887422);
            }
            $findingWriter = static function (array $finding) use ($findingsHandle): void {
                fwrite($findingsHandle, json_encode($finding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            };
        }
        try {
            $checkResult = $this->jsonReportBuilder->buildCheck($position, $className, $inspection, $preliminary, $options, $findingWriter);
        } finally {
            if ($findingsHandle !== null) {
                fclose($findingsHandle);
            }
        }
        $check = $checkResult['check'];
        if (!isset($check['facets'])) {
            // Ok and skipped checks, and checks without findings matching the filter: Index only
            return $checkResult;
        }
        file_put_contents($outputDirectory . '/' . $baseName . '.json', $this->encodeJson($input, [
            'schemaVersion' => JsonReportBuilder::SCHEMA_VERSION,
            'check' => $check,
        ]) . "\n");
        $entry = [
            'position' => $check['position'],
            'class' => $check['class'],
            'title' => $check['title'],
            'status' => $check['status'],
            'preliminary' => $check['preliminary'],
        ];
        if (isset($check['filterMatches'])) {
            $entry['filterMatches'] = $check['filterMatches'];
        }
        $entry['counts'] = array_intersect_key($check['counts'], array_flip(['findings', 'records', 'writes', 'total']));
        $entry['file'] = $baseName . '.json';
        if ($findingWriter !== null) {
            $entry['findingsFile'] = $baseName . '.findings.jsonl';
            $entry['truncated'] = $check['truncated'];
        }
        return [
            'check' => $entry,
            'tables' => $checkResult['tables'],
            'pages' => $checkResult['pages'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function writeJsonLines(string $fileName, array $lines): void
    {
        $content = '';
        foreach ($lines as $line) {
            $content .= json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($fileName, $content);
    }

    private function outputJsonError(InputInterface $input, OutputInterface $output, string $message): int
    {
        $output->writeln($this->encodeJson($input, [
            'schemaVersion' => JsonReportBuilder::SCHEMA_VERSION,
            'error' => ['message' => $message],
        ]));
        return HealthCheckResult::Error->value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(InputInterface $input, array $data): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if (!(bool)$input->getOption('compact')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($data, $flags);
    }

    /**
     * "Database analyzer" must be in a good shape: No missing tables,
     * fields and indexes. If that is not the case, we stop early.
     */
    private function checkDatabaseSchema(SymfonyStyle $io): bool
    {
        if ($this->databaseSchemaChecker->hasIncompleteTablesColumnsIndexes()) {
            $io->error(
                'Current database schema is not in sync with TCA: '
                . 'Missing tables, missing or not adapted columns or indexes were detected. '
                . 'Run "bin/typo3 extension:setup", or use the install tool "Database analyzer" '
                . 'to fix this. Then run dbdoctor again.'
            );
            return false;
        }
        return true;
    }

    /**
     * There is no point in keeping an empty sql file.
     * File position has been validated before.
     */
    private function removeEmptyFile(SymfonyStyle $io, string $file): HealthCheckResult
    {
        if (empty($file)) {
            return HealthCheckResult::Ok;
        }
        $result = true;
        if (filesize($file) === 0) {
            $result = unlink($file);
        }
        if (!$result) {
            $io->error('Unable to remove empty file "' . $file . '".');
            return HealthCheckResult::Error;
        }
        return HealthCheckResult::Ok;
    }

    private function outputSysRefIndexWarning(SymfonyStyle $io, HealthCheckMode $mode, int $exitCode): void
    {
        if (($mode === HealthCheckMode::Interactive || $mode === HealthCheckMode::Execute)
            && (($exitCode & HealthCheckResult::Broken->value) === HealthCheckResult::Broken->value)
        ) {
            $io->warning(
                'DB doctor updated something. Remember to run "bin/typo3 referenceindex:update" to update reference index.'
            );
        }
    }
}
