<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Database;

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

use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\WriteOperation;
use Lolli\Dbdoctor\Finding\WritePlan;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRun;
use Lolli\Dbdoctor\Helper\RecordsHelper;

/**
 * Executes or simulates write plans of findings. The only place changing records:
 * Each plan is one statement, shown and logged to the sql dump file of the run.
 */
final readonly class PlanExecutor
{
    public function __construct(
        private RecordsHelper $recordsHelper,
    ) {}

    /**
     * All plans of all findings in their order. Consecutive plans of one table are a
     * block with a summary before and after.
     *
     * @param list<Finding> $findings
     */
    public function execute(HealthCheckRun $run, bool $simulate, array $findings): void
    {
        $currentTableName = null;
        $counts = [];
        foreach ($findings as $finding) {
            foreach ($finding->plans as $plan) {
                if ($plan->tableName !== $currentTableName) {
                    if ($currentTableName !== null) {
                        $this->outputTableAfter($run, $simulate, $currentTableName, $counts);
                    }
                    $currentTableName = $plan->tableName;
                    $counts = [];
                    $this->outputTableBefore($run, $simulate, $currentTableName);
                }
                $this->executePlan($run, $simulate, $plan);
                $counts[$plan->operation->value] = ($counts[$plan->operation->value] ?? 0) + 1;
            }
        }
        if ($currentTableName !== null) {
            $this->outputTableAfter($run, $simulate, $currentTableName, $counts);
        }
    }

    public function executePlan(HealthCheckRun $run, bool $simulate, WritePlan $plan): void
    {
        $sql = match ($plan->operation) {
            WriteOperation::Update, WriteOperation::SoftDelete => $this->recordsHelper->updateTcaRecord($run->statements, $simulate, $plan->tableName, $this->getUid($plan), $plan->values),
            WriteOperation::Delete => $this->recordsHelper->deleteTcaRecord($run->statements, $simulate, $plan->tableName, $this->getUid($plan)),
            WriteOperation::Insert => $this->recordsHelper->insertTcaRecord($run->statements, $simulate, $plan->tableName, $plan->values),
            WriteOperation::MmDelete => $this->recordsHelper->deleteMmRows($run->statements, $simulate, $plan->tableName, $plan->where),
        };
        if ($run->sqlDumpFile && !$simulate) {
            file_put_contents($run->sqlDumpFile, $sql . "\n", \FILE_APPEND);
        }
        $run->io->text($sql);
    }

    private function getUid(WritePlan $plan): int
    {
        if ($plan->uid === null) {
            throw new \RuntimeException(
                'Write plan "' . $plan->operation->value . '" on table "' . $plan->tableName . '" needs a uid.',
                1791700001
            );
        }
        return $plan->uid;
    }

    private function outputTableBefore(HealthCheckRun $run, bool $simulate, string $tableName): void
    {
        $run->io->note(($simulate ? '[SIMULATE] ' : '') . 'Handle records on table: ' . $tableName);
    }

    /**
     * @param array<string, int> $counts Number of statements per operation
     */
    private function outputTableAfter(HealthCheckRun $run, bool $simulate, string $tableName, array $counts): void
    {
        $lines = [
            'Updated' => ($counts[WriteOperation::Update->value] ?? 0) + ($counts[WriteOperation::SoftDelete->value] ?? 0),
            'Deleted' => ($counts[WriteOperation::Delete->value] ?? 0) + ($counts[WriteOperation::MmDelete->value] ?? 0),
            'Inserted' => $counts[WriteOperation::Insert->value] ?? 0,
        ];
        foreach ($lines as $action => $count) {
            if ($count === 0) {
                continue;
            }
            $text = $action . ' "' . $count . '" records ' . ($action === 'Inserted' ? 'into' : 'from') . ' "' . $tableName . '" table';
            if ($simulate) {
                $run->io->note('[SIMULATE] ' . $text);
            } else {
                $run->io->warning($text);
            }
        }
    }
}
