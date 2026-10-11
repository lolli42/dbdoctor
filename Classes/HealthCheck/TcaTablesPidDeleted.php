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
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Not-deleted TCA records must point to not-deleted pages
 */
final readonly class TcaTablesPidDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for not-deleted records on pages set to deleted',
            [self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE],
            [
                'TCA records have a pid field set to a single page. This page must exist.',
                'This scan finds deleted=0 records pointing to pages having deleted=1.',
                'Affected records are soft deleted if possible, or removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        // Iterate all TCA tables, but ignore pages table
        foreach ($this->tcaHelper->getNextTcaTable(['pages', 'tt_content']) as $tableName) {
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $tableDeleteField = $this->tcaHelper->getDeletedField($tableName);
            $isTableSoftDeleteAware = !empty($tableDeleteField);
            $selectFields = ['uid', 'pid'];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select(...$selectFields)->from($tableName)->orderBy('uid');
            $queryBuilder->where($queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));

            if ($isTableSoftDeleteAware) {
                // Do not consider deleted records: Records pointing to a not-existing page have been
                // caught before, we want to find non-deleted records pointing to deleted pages.
                // Still, TCA tables without soft-delete, must point to not-deleted pages.
                $queryBuilder->andWhere($queryBuilder->expr()->eq($tableDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                // Records pointing to pid 0 are ok, check all others.
                try {
                    $pageRow = $this->recordsHelper->getRecord($run->statements, 'pages', ['uid', 'deleted'], (int)$row['pid']);
                    if ((int)$pageRow['deleted'] === 1) {
                        $findings[] = new Finding(
                            new RecordKey($tableName, (int)$row['uid']),
                            (int)$row['pid'],
                            [$this->softOrHardDeleteChange($tableName, $row)],
                            new Reason('page-deleted', 'Page "' . $row['pid'] . '" is soft-deleted', ['pid' => (int)$row['pid']]),
                        );
                    }
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new EarlierCheckNotFixedException(
                        'Record with uid="' . $row['uid'] . '" on table "' . $tableName . '"'
                        . ' has pid="' . $row['pid'] . '", but that page does not exist.'
                        . ' An earlier check finds and fixes this.',
                        1647793650
                    );
                }
            }
        }
        return $findings;
    }
}
