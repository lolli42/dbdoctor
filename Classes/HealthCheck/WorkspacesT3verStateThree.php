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

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TYPO3 v11 migrated workspace related record content having t3ver_state=3 into
 * t3ver_state=4 records and removed them.
 * This check finds leftovers and removes them from the database.
 */
final class WorkspacesT3verStateThree extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records with t3ver_state=3');
        $this->outputTags($io, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'The workspace related field state t3ver_state=3 has been removed with TYPO3 v11.',
            'Until TYPO3 v11, they were paired with a t3ver_state=4 record. A core upgrade',
            'wizard migrates affected records. This check removes left over records having t3ver_state=3.',
            'If this check finds records, it may indicate the upgrade wizard "WorkspaceMovePlaceholderRemovalMigration"',
            'has not been run. ABORT NOW and run the wizard, it is included in TYPO3 core v11 and v12.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // We want to find "deleted=1" records, so obviously especially the "delete" restriction is removed.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid', 't3ver_state')->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_state', 3),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                $affectedRows[$tableName][(int)$row['uid']] = $row;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecords($io, $simulate, $affectedRecords);
    }
}
