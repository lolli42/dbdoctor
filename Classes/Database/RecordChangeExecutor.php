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
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRun;
use Lolli\Dbdoctor\Helper\RecordsHelper;

/**
 * Executes or simulates changes of findings. The only place changing records:
 * Each change is one statement, shown and logged to the sql dump file of the run.
 */
final readonly class RecordChangeExecutor
{
    public function __construct(
        private RecordsHelper $recordsHelper,
    ) {}

    /**
     * All changes of all findings in their order. Consecutive changes of one table are a
     * block with a summary before and after.
     *
     * @param list<Finding> $findings
     */
    public function execute(HealthCheckRun $run, bool $simulate, array $findings): void
    {
        $currentTableName = null;
        $counts = [];
        foreach ($findings as $finding) {
            foreach ($finding->changes as $change) {
                if ($change->tableName !== $currentTableName) {
                    if ($currentTableName !== null) {
                        $this->outputTableAfter($run, $simulate, $currentTableName, $counts);
                    }
                    $currentTableName = $change->tableName;
                    $counts = [];
                    $this->outputTableBefore($run, $simulate, $currentTableName);
                }
                $this->executeChange($run, $simulate, $change);
                $counts[$change->type->value] = ($counts[$change->type->value] ?? 0) + 1;
            }
        }
        if ($currentTableName !== null) {
            $this->outputTableAfter($run, $simulate, $currentTableName, $counts);
        }
    }

    private function executeChange(HealthCheckRun $run, bool $simulate, RecordChange $change): void
    {
        $sql = match ($change->type) {
            RecordChangeType::Update, RecordChangeType::SoftDelete => $this->recordsHelper->updateTcaRecord($run->statements, $simulate, $change->tableName, $this->getUid($change), $change->values),
            RecordChangeType::Delete => $this->recordsHelper->deleteTcaRecord($run->statements, $simulate, $change->tableName, $this->getUid($change)),
            RecordChangeType::Insert => $this->recordsHelper->insertTcaRecord($run->statements, $simulate, $change->tableName, $change->values),
            RecordChangeType::MmDelete => $this->recordsHelper->deleteMmRows($run->statements, $simulate, $change->tableName, $change->where),
        };
        if ($run->sqlDumpFile && !$simulate) {
            file_put_contents($run->sqlDumpFile, $sql . "\n", \FILE_APPEND);
        }
        $run->io->text($sql);
    }

    private function getUid(RecordChange $change): int
    {
        if ($change->uid === null) {
            throw new \RuntimeException(
                'Change "' . $change->type->value . '" on table "' . $change->tableName . '" needs a uid.',
                1791700001
            );
        }
        return $change->uid;
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
            'Updated' => ($counts[RecordChangeType::Update->value] ?? 0) + ($counts[RecordChangeType::SoftDelete->value] ?? 0),
            'Deleted' => ($counts[RecordChangeType::Delete->value] ?? 0) + ($counts[RecordChangeType::MmDelete->value] ?? 0),
            'Inserted' => $counts[RecordChangeType::Insert->value] ?? 0,
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
