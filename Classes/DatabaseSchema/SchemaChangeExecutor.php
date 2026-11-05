<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\DatabaseSchema;

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

use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Execute schema change statements in the given order.
 *
 * Execution stops at the first failing statement: On sqlite, one change of a table is a sequence
 * of statements that re-creates the table, continuing after a failure could lose data. The core
 * schema migrator continues instead, and extension:setup does not report failures at all.
 */
final readonly class SchemaChangeExecutor
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private CacheManager $cacheManager,
        #[Autowire(service: 'cache.runtime')]
        private FrontendInterface $runtimeCache,
    ) {}

    /**
     * @param list<SchemaChange> $changes
     * @param string $sqlFile Absolute path to append executed statements to, empty for none
     */
    public function execute(array $changes, string $sqlFile): SchemaExecution
    {
        $executedCount = 0;
        try {
            foreach ($changes as $change) {
                try {
                    $this->connectionPool->getConnectionByName($change->connectionName)->executeStatement($change->statement);
                } catch (DBALException $e) {
                    return new SchemaExecution($executedCount, $change, $e->getMessage());
                }
                $executedCount++;
                if ($sqlFile !== '') {
                    file_put_contents($sqlFile, $change->statement . ";\n", \FILE_APPEND);
                }
            }
            return new SchemaExecution($executedCount);
        } finally {
            if ($executedCount > 0) {
                // As the core schema migrator does: Schema details of tables are cached.
                $this->cacheManager->getCache('database_schema')->flush();
                $this->runtimeCache->flush();
            }
        }
    }
}
