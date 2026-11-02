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

use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Helper\LoopHelper;
use TYPO3\CMS\Core\Database\Connection;

/**
 * sys_category records build a tree via their field "parent". The backend category tree is built
 * from the root (parent 0) downwards: Categories in a loop (a category being its own parent, or
 * 1 -> 2 -> 1) are never reached from the root, they and their sub categories are not shown in any
 * category tree and can not be selected anymore. There is no endless loop, the tree keeps track of
 * visited nodes. The frontend uses categories flat and does not follow "parent". The DataHandler
 * does not prevent loops, they come from the list module, imports or scripts.
 *
 * Only categories shown in the category tree are followed: Live, not soft-deleted, default language
 * or "all languages", as the tree selects them. The category with the lowest uid of each loop is moved
 * to the root (parent 0): The loop is shown in the tree again, all category assignments are kept.
 *
 * Not handled: Categories whose parent is soft-deleted. The core keeps sub categories when a category
 * is deleted in the backend, they are not shown in the tree, and restoring the parent with the recycler
 * shows them again. A parent that does not exist is handled by SelectFieldRelationMissing.
 */
final readonly class SysCategoryParentLoop extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private LoopHelper $loopHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for sys_category records with parent loops',
            [ActionTag::UpdateFields],
            [
                'Categories build a tree via their field "parent". Categories in a loop, for instance a',
                'category being its own parent, are never reached from the root of the backend category',
                'tree: They and their sub categories can not be selected anymore. The category with the',
                'lowest uid of each loop is moved to the root of the tree, category assignments are kept.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid', 'parent')
            ->from('sys_category')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField('sys_category');
        if ($workspaceIdField !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        $result = $queryBuilder->orderBy('uid')->executeQuery();
        // uid => parent uid of categories having a parent, uid => pid of all categories
        $parents = [];
        $pids = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $pids[(int)$row['uid']] = (int)$row['pid'];
            if ((int)$row['parent'] > 0) {
                $parents[(int)$row['uid']] = (int)$row['parent'];
            }
        }
        // A parent that is not followed (soft-deleted, missing, other language) ends a chain.
        $next = static fn(int $uid): ?int => isset($parents[$uid], $pids[$parents[$uid]]) ? $parents[$uid] : null;
        $findings = [];
        foreach ($this->loopHelper->getLoops(array_keys($parents), $next) as $loop) {
            $uid = min($loop);
            $findings[] = new Finding(
                new RecordKey('sys_category', $uid),
                $pids[$uid],
                [new RecordChange(RecordChangeType::Update, 'sys_category', $uid, ['parent' => ['value' => 0, 'type' => Connection::PARAM_INT]])],
                new Reason(
                    'parent-loop',
                    'Parent loop: ' . implode(' -> ', [...$loop, $loop[0]]),
                    ['loop' => $loop]
                ),
            );
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['parent'];
    }
}
