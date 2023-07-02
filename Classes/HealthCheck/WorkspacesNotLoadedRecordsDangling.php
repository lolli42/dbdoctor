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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * When ext:workspaces is not loaded at all, there shouldn't be any record in
 * a workspaces enabled TCA table (['ctrl']['versioningWS'] = true) having
 * 't3ver_wsid' != 0.
 *
 * This check detects and deletes all records having t3ver_wsid != 0 when ext:workspaces is not loaded.
 */
final class WorkspacesNotLoadedRecordsDangling extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for workspace records when ext:workspaces is not loaded');
        $io->text([
            '[DELETE] When extension "workspaces" is not loaded, there should be no workspace overlay',
            '         records (t3ver_wsid != 0). This check deletes all workspace related records if',
            '         the extension is not loaded. Think about this twice: If workspaces is a thing',
            '         in your instance, the extension must be loaded, otherwise this check will',
            '         remove all existing workspace overlay records!',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        if (ExtensionManagementUtility::isLoaded('workspaces')) {
            // Nothing to do when ext:workspaces is loaded
            return [];
        }
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextWorkspaceEnabledTcaTable() as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Delete "deleted=1" records as well, when they have t3ver_wsid != 0
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', 't3ver_wsid')->from($tableName)
                ->where(
                    $queryBuilder->expr()->neq('t3ver_wsid', 0)
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
        $this->deleteAllRecords($io, $simulate, $affectedRecords);
    }
}
