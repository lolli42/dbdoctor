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

use Lolli\Dbdoctor\DatabaseSchema\SchemaChange;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeCategory;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeExecutor;
use Lolli\Dbdoctor\DatabaseSchema\SchemaInspector;
use Lolli\Dbdoctor\DatabaseSchema\SchemaLevel;
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\Renderer\SchemaChangesRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Compare the database schema with the table definitions.
 */
final class SchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaInspector $schemaInspector,
        private readonly SchemaChangesRenderer $schemaChangesRenderer,
        private readonly SchemaChangeExecutor $schemaChangeExecutor,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_REQUIRED,
            'check|interactive|execute - check: show statements, interactive: ask per category before executing,'
            . ' execute: execute all statements of the level (!)',
            'check'
        );
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_REQUIRED,
            'log executed statements to a given absolute file, mandatory with "--mode execute"'
        );
        $this->addOption(
            'level',
            null,
            InputOption::VALUE_REQUIRED,
            SchemaLevel::Required->value . '|' . SchemaLevel::Recommended->value . '|' . SchemaLevel::All->value
            . ' - required: missing tables, columns and indexes, recommended: plus changed ones, all: plus unused and obsolete ones',
            SchemaLevel::Recommended->value
        );
        $this->setHelp(
            'Compare the database schema with the table definitions of ext_tables.sql files and TCA,'
            . ' like the install tool "Analyze Database Structure", and show or execute the statements'
            . ' that update the schema:' . "\n"
            . '- missing: create tables, add columns and indexes. dbdoctor:health does not run without.' . "\n"
            . '- changed: change columns, indexes and table options.' . "\n"
            . '- unused: rename tables and columns without definition to "zzz_deleted_*".' . "\n"
            . '- obsolete: drop "zzz_deleted_*" tables and columns and indexes without definition. Data is lost.' . "\n"
            . 'Statements are executed per category in this order. Execution stops at the first failing statement.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());
        $modeOption = (string)$input->getOption('mode');
        $mode = match ($modeOption) {
            'check' => HealthCheckMode::Check,
            'interactive' => HealthCheckMode::Interactive,
            'execute' => HealthCheckMode::Execute,
            default => null,
        };
        if ($mode === null) {
            $io->error('Invalid mode "' . $modeOption . '". Use -h for help on valid options.');
            return HealthCheckResult::Error->value;
        }
        $levelOption = (string)$input->getOption('level');
        $level = SchemaLevel::tryFrom($levelOption);
        if ($level === null) {
            $io->error('Invalid level "' . $levelOption . '". Use -h for help on valid options.');
            return HealthCheckResult::Error->value;
        }
        if ($mode === HealthCheckMode::Interactive && !$input->isInteractive()) {
            $io->error('Mode "interactive" needs an interactive terminal, use "--mode execute" otherwise.');
            return HealthCheckResult::Error->value;
        }
        $file = (string)$input->getOption('file');
        if ($mode === HealthCheckMode::Check) {
            if ($file !== '') {
                $io->error('Option "--file" not available with "--mode check"');
                return HealthCheckResult::Error->value;
            }
            return $this->check($io, $level);
        }
        if ($mode === HealthCheckMode::Execute && $file === '') {
            $io->error('Option "--file" with an absolute path to a file that does not exist yet is mandatory with "--mode execute".');
            return HealthCheckResult::Error->value;
        }
        if ($file !== '') {
            $fileError = $this->createFile($file);
            if ($fileError !== '') {
                $io->error($fileError);
                return HealthCheckResult::Error->value;
            }
        }
        $result = $this->update($io, $mode, $level, $file);
        if ($file !== '' && filesize($file) === 0 && !unlink($file)) {
            $io->error('Unable to remove empty file "' . $file . '".');
            $result |= HealthCheckResult::Error->value;
        }
        return $result;
    }

    private function check(SymfonyStyle $io, SchemaLevel $level): int
    {
        $shown = [];
        $hidden = [];
        foreach ($this->schemaInspector->getChanges() as $change) {
            if ($this->schemaInspector->isInLevel($change->category, $level)) {
                $shown[] = $change;
            } else {
                $hidden[$change->category->value] = ($hidden[$change->category->value] ?? 0) + 1;
            }
        }
        $this->schemaChangesRenderer->render($io, $shown);
        if ($hidden !== []) {
            $counts = [];
            foreach ($hidden as $category => $count) {
                $counts[] = $count . ' ' . $category;
            }
            $io->text('Statements not shown with level "' . $level->value . '": ' . implode(', ', $counts) . '. Use "--level all" to show them.');
            $io->newLine();
        }
        if ($shown === []) {
            $io->success('Database schema is up to date with level "' . $level->value . '".');
            return HealthCheckResult::Ok->value;
        }
        $io->warning('Statements to update the database schema with level "' . $level->value . '": ' . count($shown) . '.');
        return HealthCheckResult::Broken->value;
    }

    /**
     * Statements are determined again before each category: Executing one category may change
     * statements of the next one, on sqlite statements re-create whole tables.
     *
     * Obsolete statements are executed only when they were obsolete when the run started: A table
     * or column renamed to "zzz_deleted_*" as unused is not dropped in the same run.
     */
    private function update(SymfonyStyle $io, HealthCheckMode $mode, SchemaLevel $level, string $file): int
    {
        $result = HealthCheckResult::Ok->value;
        $initialHashes = array_map(
            static fn(SchemaChange $change): string => $change->hash,
            $this->schemaInspector->getChanges($level)
        );
        foreach (SchemaChangeCategory::cases() as $category) {
            if (!$this->schemaInspector->isInLevel($category, $level)) {
                continue;
            }
            $changes = array_values(array_filter(
                $this->schemaInspector->getChanges($level),
                static fn(SchemaChange $change): bool => $change->category === $category
                    && ($category !== SchemaChangeCategory::Obsolete || in_array($change->hash, $initialHashes, true))
            ));
            if ($changes === []) {
                continue;
            }
            $this->schemaChangesRenderer->render($io, $changes);
            if ($mode === HealthCheckMode::Interactive) {
                $answer = $this->ask($io, count($changes));
                if ($answer === 'a') {
                    return $result | HealthCheckResult::Abort->value;
                }
                if ($answer === 'n') {
                    continue;
                }
            }
            if ($file !== '') {
                file_put_contents($file, '# dbdoctor:schema ' . $category->value . "\n", \FILE_APPEND);
            }
            $execution = $this->schemaChangeExecutor->execute($changes, $file);
            if ($execution->executedCount > 0) {
                $result |= HealthCheckResult::Broken->value;
            }
            if ($execution->failedChange !== null) {
                $io->error([
                    'Statements executed: ' . $execution->executedCount . ', then a statement failed, execution stopped.',
                    'Statement: ' . $execution->failedChange->statement,
                    'Error: ' . $execution->errorMessage,
                ]);
                return $result | HealthCheckResult::Error->value;
            }
            $io->text('Statements executed: ' . $execution->executedCount . '.');
            $io->newLine();
        }
        $left = count($this->schemaInspector->getChanges($level));
        if ($left === 0) {
            $io->success('Database schema is up to date with level "' . $level->value . '".');
            return $result;
        }
        $io->warning(
            'Statements left to update the database schema with level "' . $level->value . '": ' . $left . '.'
            . ' Skipped categories, or changes that need another run: Run again to see them.'
        );
        return $result | HealthCheckResult::Broken->value;
    }

    /**
     * @return 'e'|'n'|'a'
     */
    private function ask(SymfonyStyle $io, int $count): string
    {
        while (true) {
            $answer = $io->ask('<info>Execute these ' . $count . ' statements [e,n,a,?]?</info> ', '?');
            if ($answer === 'e' || $answer === 'n' || $answer === 'a') {
                return $answer;
            }
            $io->text([
                '    e - EXECUTE these statements!',
                '    n - NEXT category, do not execute these statements',
                '    a - ABORT now',
                '    ? - HELP',
            ]);
        }
    }

    /**
     * @return string Error message, empty when the file has been created
     */
    private function createFile(string $file): string
    {
        if (!PathUtility::isAbsolutePath($file)) {
            return 'Invalid file "' . $file . '". Must be an absolute path.';
        }
        if (file_exists($file)) {
            return 'Invalid file "' . $file . '". File exists already.';
        }
        if (file_put_contents($file, '') === false) {
            return 'Invalid file "' . $file . '". Unable to create.';
        }
        return '';
    }
}
