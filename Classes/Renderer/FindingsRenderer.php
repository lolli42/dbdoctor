<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Renderer;

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
use Lolli\Dbdoctor\Finding\MmKey;
use Lolli\Dbdoctor\Finding\RecordFieldKey;
use Lolli\Dbdoctor\HealthCheck\AbstractHealthCheck;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\HealthCheckRun;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console output of a health check and its findings.
 */
final readonly class FindingsRenderer
{
    public function __construct(
        private AffectedPagesRenderer $affectedPagesRenderer,
        private RecordsRenderer $recordsRenderer,
    ) {}

    /**
     * A check without findings is a single line, header and description are shown with -v.
     */
    public function ok(SymfonyStyle $io, HealthCheckInterface $check): void
    {
        $io->writeln('<info>OK</info>  ' . (new \ReflectionClass($check))->getShortName());
    }

    /**
     * Section title, class name, actions and description of the check.
     */
    public function header(SymfonyStyle $io, HealthCheckInterface $check): void
    {
        $description = $check->getDescription();
        $io->section($description->title);
        $this->outputClass($io, $check);
        $this->outputTags($io, ...$description->tags);
        $io->text($description->lines);
    }

    public function skipped(SymfonyStyle $io, string $reason): void
    {
        $io->warning([
            'Check skipped: It relies on findings of earlier checks being fixed.',
            $reason,
            'Fix findings of earlier checks, then run check mode again.',
        ]);
    }

    public function reloaded(SymfonyStyle $io): void
    {
        $io->warning('Records changed since they were shown, findings reloaded. Nothing executed.');
    }

    /**
     * @param list<Finding> $findings
     */
    public function summary(SymfonyStyle $io, array $findings): void
    {
        $danglingRows = $this->groupFindingsByTable($findings);
        if (!count($danglingRows)) {
            $io->success('No affected records found');
        } else {
            $ioText = [
                'Found affected records in ' . count($danglingRows) . ' tables:',
            ];
            $tablesString = '';
            foreach ($danglingRows as $tableName => $rows) {
                if (!empty($tablesString)) {
                    $tablesString .= "\n";
                }
                $tablesString .= '"' . $tableName . '": ' . count($rows) . ' records';
            }
            $ioText[] = $tablesString;
            $io->warning($ioText);
        }
    }

    /**
     * @param list<Finding> $findings
     */
    public function pages(HealthCheckRun $run, array $findings): void
    {
        $rows = [];
        foreach ($findings as $finding) {
            if ($finding->pid === null || $finding->key instanceof MmKey) {
                continue;
            }
            $rows[$finding->key->tableName][] = [
                'uid' => $finding->key->uid,
                'pid' => $finding->pid,
            ];
        }
        $run->io->note('Found records per page:');
        $affectedPages = $this->affectedPagesRenderer->render($run->statements, $rows);
        $run->io->table($affectedPages['header'], $affectedPages['rows']);
    }

    /**
     * Record findings are shown with their record, MM findings with their key.
     *
     * @param list<Finding> $findings
     */
    public function details(HealthCheckRun $run, HealthCheckInterface $check, array $findings): void
    {
        foreach ($this->groupFindingsByTable($findings) as $tableName => $tableFindings) {
            if ($tableFindings[0]->key instanceof MmKey) {
                $run->io->note('MM table "' . $tableName . '":');
                $header = ['uid_local', 'local table', 'field'];
                $rows = [];
                foreach ($tableFindings as $finding) {
                    /** @var MmKey $key */
                    $key = $finding->key;
                    $row = [$key->uidLocal, $key->localTableName, $key->localFieldName];
                    foreach ($finding->evidence as $value) {
                        $row[] = is_scalar($value) ? (string)$value : json_encode($value, JSON_THROW_ON_ERROR);
                    }
                    $rows[] = $row;
                }
                $run->io->table(array_merge($header, array_keys($tableFindings[0]->evidence)), $rows);
                continue;
            }
            $hasReason = false;
            $extraDbFields = $check->getDetailDbFields($tableName);
            $rows = [];
            foreach ($tableFindings as $finding) {
                $key = $finding->key;
                if ($key instanceof MmKey) {
                    continue;
                }
                if ($key instanceof RecordFieldKey && !in_array($key->fieldName, $extraDbFields, true)) {
                    $extraDbFields[] = $key->fieldName;
                }
                $row = ['uid' => $key->uid];
                if ($finding->reason !== null) {
                    $hasReason = true;
                    $row['_reasonBroken'] = $finding->reason->message;
                }
                $rows[] = $row;
            }
            if ($hasReason) {
                $rows = array_map(static fn(array $row): array => $row + ['_reasonBroken' => ''], $rows);
            }
            $reasonField = $hasReason ? '_reasonBroken' : '';
            $extraCtrlFields = $check->getDetailCtrlFields($tableName);
            $run->io->note('Table "' . $tableName . '":');
            $run->io->table(
                $this->recordsRenderer->getHeader($tableName, $reasonField, $extraCtrlFields, $extraDbFields),
                $this->recordsRenderer->getRows($run->statements, $tableName, $rows, $reasonField, $extraCtrlFields, $extraDbFields)
            );
        }
    }

    public function help(SymfonyStyle $io): void
    {
        $io->text([
            '    e - EXECUTE suggested changes!',
            '    s - SIMULATE suggested changes, no execution',
            '    a - ABORT now',
            '    r - RELOAD this check',
            '    p - SHOW records by page',
            '    d - SHOW record details',
            '    ? - HELP',
        ]);
    }

    /**
     * Findings grouped by the table they are counted for: The record table, or the MM table
     * for MM rows of a missing local record.
     *
     * @param list<Finding> $findings
     * @return array<string, list<Finding>>
     */
    private function groupFindingsByTable(array $findings): array
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $tableName = $finding->key instanceof MmKey ? $finding->key->mmTableName : $finding->key->tableName;
            $grouped[$tableName][] = $finding;
        }
        return $grouped;
    }

    /**
     * Render a list of tags to declare the type of changes a check applies.
     */
    private function outputTags(SymfonyStyle $io, string ...$tags): void
    {
        $wrappedTags = [];
        foreach ($tags as $tag) {
            if ($tag === AbstractHealthCheck::TAG_RISKY) {
                $wrappedTags[] = '<error>' . $tag . '</error>';
            } else {
                $wrappedTags[] = '<comment>' . $tag . '</comment>';
            }
        }
        $io->text('Actions: ' . implode(', ', $wrappedTags));
    }

    private function outputClass(SymfonyStyle $io, HealthCheckInterface $check): void
    {
        $io->text(('Class: <comment>' . (new \ReflectionClass($check))->getShortName()) . '</comment>');
    }
}
