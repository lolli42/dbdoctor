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

/**
 * Prepared statements of one health check run. A resource like a connection, neither data
 * object nor service: Created per run by AbstractHealthCheck->handle(), which releases it
 * when the run ends. Services preparing statements, like RecordsHelper, receive it as argument
 * and stay stateless.
 */
final class PreparedStatements
{
    /**
     * @var array<string, PreparedStatement>
     */
    private array $statements = [];

    public function get(string $key): ?PreparedStatement
    {
        return $this->statements[$key] ?? null;
    }

    public function add(string $key, PreparedStatement $preparedStatement): PreparedStatement
    {
        $this->statements[$key] = $preparedStatement;
        return $preparedStatement;
    }

    /**
     * Doctrine statements have no close(): Dropping the references lets the driver close them.
     */
    public function release(): void
    {
        $this->statements = [];
    }
}
