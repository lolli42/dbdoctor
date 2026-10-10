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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Finding\WriteOperation;
use Lolli\Dbdoctor\Finding\WritePlan;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * All TCA records must point to existing pages
 */
final readonly class TcaTablesPidMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records on not existing pages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'TCA records have a pid field set to a single page. This page must exist.',
            'Records on pages that do not exist anymore are deleted.',
        ]);
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextTcaTable(['pages', 'sys_workspace', 'tt_content']) as $tableName) {
            // Iterate all TCA tables, but ignore some tables: pages of course,
            // and tt_content has been done with TtContentPidMissing already.
            // @todo: sys_workspace records must be pid=0 anyways - eventually have a check for this?
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Consider deleted records: If the pid does not exist, they should be deleted, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid')->from($tableName)
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $pid = (int)$row['pid'];
                if ($pid === 0) {
                    // Records pointing to pid 0 are ok.
                    continue;
                }
                try {
                    $this->recordsHelper->getRecord($run->statements, 'pages', ['uid'], $pid);
                } catch (NoSuchRecordException $e) {
                    $findings[] = new Finding(
                        new RecordKey($tableName, (int)$row['uid']),
                        $pid,
                        [new WritePlan(WriteOperation::Delete, $tableName, (int)$row['uid'])],
                        new Reason('page-missing', 'Page "' . $pid . '" does not exist', ['pid' => $pid]),
                    );
                }
            }
        }
        return $findings;
    }
}
