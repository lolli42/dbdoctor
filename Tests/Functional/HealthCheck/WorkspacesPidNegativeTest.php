<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\HealthCheck;

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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\HealthCheck\WorkspacesPidNegative;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

class WorkspacesPidNegativeTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // pid is declared unsigned. mysql and mariadb enforce this, sqlite and postgres do not.
        // Make pid signed for mysql and mariadb, as it was before TYPO3 v10 migrated pid=-1
        // workspace records. The instance is shared with other test classes, tearDown() reverts this.
        $this->alterPidColumn('pages', 'INT NOT NULL DEFAULT 0');
        $this->alterPidColumn('tt_content', 'INT NOT NULL DEFAULT 0');
    }

    protected function tearDown(): void
    {
        $this->alterPidColumn('pages', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $this->alterPidColumn('tt_content', 'INT UNSIGNED NOT NULL DEFAULT 0');
        parent::tearDown();
    }

    private function alterPidColumn(string $table, string $definition): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable($table);
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // Rows with negative pid would block making pid unsigned again in tearDown().
            $connection->truncate($table);
            $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($table) . ' MODIFY pid ' . $definition);
        }
    }

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesPidNegativeImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var WorkspacesPidNegative $subject */
        $subject = $this->get(WorkspacesPidNegative::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesPidNegativeImport.csv');
        /** @var WorkspacesPidNegative $subject */
        $subject = $this->get(WorkspacesPidNegative::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/WorkspacesPidNegativeFixed.csv');
    }
}
