<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

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

/**
 * Stateless service finding loops of records pointing to another record of the same table,
 * like shortcut pages or the parent of categories.
 */
final readonly class LoopHelper
{
    /**
     * Loops of a graph where each node has at most one successor, in order of their first node.
     * Each loop starts with the node it was entered with.
     *
     * @param array<int, int> $nodes Sorted
     * @param \Closure(int): ?int $next
     * @return list<non-empty-list<int>>
     */
    public function getLoops(array $nodes, \Closure $next): array
    {
        $isDone = [];
        $loops = [];
        foreach ($nodes as $startUid) {
            if (isset($isDone[$startUid])) {
                continue;
            }
            // Walk until a node without successor, a node done before, or a node of this walk.
            $path = [];
            $positionInPath = [];
            $uid = $startUid;
            while ($uid !== null && !isset($isDone[$uid]) && !isset($positionInPath[$uid])) {
                $positionInPath[$uid] = count($path);
                $path[] = $uid;
                $uid = $next($uid);
            }
            if ($uid !== null && isset($positionInPath[$uid])) {
                $loops[] = [$uid, ...array_slice($path, $positionInPath[$uid] + 1)];
            }
            foreach ($path as $pathUid) {
                $isDone[$pathUid] = true;
            }
        }
        return $loops;
    }
}
