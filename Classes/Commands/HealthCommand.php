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
use Lolli\Dbdoctor\HealthCheck\HealthCheckMode;
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRunner;
use Lolli\Dbdoctor\HealthFactory\HealthFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        $this->setHelp('This is an interactive command to go through a list of database health checks finding inconsistencies.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
