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

use Lolli\Dbdoctor\DatabaseSchema\SchemaChange;
use Lolli\Dbdoctor\DatabaseSchema\SchemaChangeCategory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Schema change statements as a section per category, used by dbdoctor:schema and dbdoctor:health.
 */
final readonly class SchemaChangesRenderer
{
    /**
     * @param list<SchemaChange> $changes Ordered by category
     */
    public function render(SymfonyStyle $io, array $changes): void
    {
        $category = null;
        foreach ($changes as $change) {
            if ($change->category !== $category) {
                if ($category !== null) {
                    $io->newLine();
                }
                $category = $change->category;
                $io->section($this->getSectionTitle($category));
            }
            $lines = [];
            if ($change->connectionName !== ConnectionPool::DEFAULT_CONNECTION_NAME) {
                $lines[] = 'Connection: ' . $change->connectionName;
            }
            $lines[] = $change->statement;
            if ($change->currentDefinition !== null) {
                $lines[] = 'Current: ' . $change->currentDefinition;
            }
            if ($change->rowCount !== null) {
                $lines[] = 'Rows: ' . $change->rowCount;
            }
            $io->writeln(' * ' . OutputFormatter::escape(implode("\n   ", $lines)));
        }
        if ($category !== null) {
            $io->newLine();
        }
    }

    private function getSectionTitle(SchemaChangeCategory $category): string
    {
        return match ($category) {
            SchemaChangeCategory::Missing => 'Missing tables, columns and indexes',
            SchemaChangeCategory::Changed => 'Changed columns, indexes and table options',
            SchemaChangeCategory::Unused => 'Unused tables and columns, to rename',
            SchemaChangeCategory::Obsolete => 'Obsolete tables, columns and indexes, to drop',
        };
    }
}
