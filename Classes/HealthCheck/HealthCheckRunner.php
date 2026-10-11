<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

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

use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Database\RecordChangeExecutor;
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Renderer\FindingsRenderer;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs a health check in one of the modes: The check detects findings, this shows, simulates
 * and executes them. RecordChangeExecutor is the only place changing records.
 */
final readonly class HealthCheckRunner
{
    public function __construct(
        private RecordChangeExecutor $recordChangeExecutor,
        private FindingsRenderer $findingsRenderer,
    ) {}

    public function handle(HealthCheckInterface $check, SymfonyStyle $io, int $mode, string $file): int
    {
        $run = new HealthCheckRun($io, $file, new PreparedStatements());
        try {
            return $this->handleRun($check, $run, $mode);
        } finally {
            $run->statements->release();
        }
    }

    private function handleRun(HealthCheckInterface $check, HealthCheckRun $run, int $mode): int
    {
        $io = $run->io;
        try {
            return $this->handleFindings($check, $run, $mode, $check->detect($run));
        } catch (EarlierCheckNotFixedException $e) {
            // Thrown by detection. Interactive and execute mode detect again later, but
            // only check mode turns this into a skip.
            if ($mode !== HealthCheckInterface::MODE_CHECK) {
                throw $e;
            }
            // Check mode reports findings of earlier checks, but does not fix them. This
            // check relies on those fixes and can not run.
            $this->findingsRenderer->header($io, $check);
            $this->findingsRenderer->skipped($io, $e->getMessage());
            return HealthCheckInterface::RESULT_BROKEN;
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function handleFindings(HealthCheckInterface $check, HealthCheckRun $run, int $mode, array $findings): int
    {
        $io = $run->io;
        if (empty($findings) && !$io->isVerbose()) {
            $this->findingsRenderer->ok($io, $check);
            return HealthCheckInterface::RESULT_OK;
        }
        $this->findingsRenderer->header($io, $check);
        $this->findingsRenderer->summary($io, $findings);
        if (empty($findings)) {
            return HealthCheckInterface::RESULT_OK;
        }
        if ($mode === HealthCheckInterface::MODE_CHECK) {
            return HealthCheckInterface::RESULT_BROKEN;
        }
        if ($mode === HealthCheckInterface::MODE_EXECUTE) {
            $this->writeSqlDumpFileHeader($check, $run);
            $this->recordChangeExecutor->execute($run, false, $findings);
            return HealthCheckInterface::RESULT_BROKEN;
        }
        while (true) {
            switch ($io->ask('<info>Handle records [e,s,a,r,p,d,?]?</info> ', '?')) {
                case 'e':
                    // Execute exactly the findings shown, on current data: Records may have changed
                    // since detection, for instance by editors. Detect again, and when the findings
                    // differ, show them instead of executing. Findings are data objects, == compares
                    // them with all their properties.
                    $currentFindings = $check->detect($run);
                    if ($currentFindings != $findings) {
                        $findings = $currentFindings;
                        $this->findingsRenderer->reloaded($io);
                        $this->findingsRenderer->summary($io, $findings);
                        if (empty($findings)) {
                            return HealthCheckInterface::RESULT_OK;
                        }
                        break;
                    }
                    // Then detect again to show what is left.
                    $this->writeSqlDumpFileHeader($check, $run);
                    $this->recordChangeExecutor->execute($run, false, $findings);
                    $findings = $check->detect($run);
                    $this->findingsRenderer->summary($io, $findings);
                    if (empty($findings)) {
                        return HealthCheckInterface::RESULT_BROKEN;
                    }
                    break;
                case 's':
                    $this->recordChangeExecutor->execute($run, true, $findings);
                    $this->findingsRenderer->summary($io, $findings);
                    break;
                case 'a':
                    return HealthCheckInterface::RESULT_ABORT;
                case 'r':
                    $findings = $check->detect($run);
                    $this->findingsRenderer->summary($io, $findings);
                    if (empty($findings)) {
                        return HealthCheckInterface::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->findingsRenderer->summary($io, $findings);
                    $this->findingsRenderer->pages($run, $findings);
                    break;
                case 'd':
                    $this->findingsRenderer->summary($io, $findings);
                    $this->findingsRenderer->details($run, $check, $findings);
                    break;
                case 'h':
                default:
                    $this->findingsRenderer->help($io);
                    break;
            }
        }
    }

    /**
     * Comment "Triggered by" in the dump file before each pass of changes, to find out
     * which specific health check triggered the following statements when reading the
     * dump file. A service with multiple tags runs at multiple chain positions, each pass
     * gets its own header.
     */
    private function writeSqlDumpFileHeader(HealthCheckInterface $check, HealthCheckRun $run): void
    {
        if ($run->sqlDumpFile) {
            file_put_contents($run->sqlDumpFile, '# Triggered by ' . $check::class . "\n", \FILE_APPEND);
        }
    }
}
