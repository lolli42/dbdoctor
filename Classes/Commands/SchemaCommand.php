<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Commands;

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

use Lolli\Dbdoctor\DatabaseSchema\SchemaInspector;
use Lolli\Dbdoctor\DatabaseSchema\SchemaLevel;
use Lolli\Dbdoctor\HealthCheck\HealthCheckResult;
use Lolli\Dbdoctor\Renderer\SchemaChangesRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compare the database schema with the table definitions.
 */
final class SchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaInspector $schemaInspector,
        private readonly SchemaChangesRenderer $schemaChangesRenderer,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'level',
            null,
            InputOption::VALUE_REQUIRED,
            SchemaLevel::Required->value . '|' . SchemaLevel::Recommended->value . '|' . SchemaLevel::All->value
            . ' - required: missing tables, columns and indexes, recommended: plus changed ones, all: plus unused and obsolete ones',
            SchemaLevel::Recommended->value
        );
        $this->setHelp(
            'Compare the database schema with the table definitions of ext_tables.sql files and TCA,'
            . ' like the install tool "Analyze Database Structure". Read-only, the command shows the statements'
            . ' that would update the schema:' . "\n"
            . '- missing: create tables, add columns and indexes. dbdoctor:health does not run without.' . "\n"
            . '- changed: change columns, indexes and table options.' . "\n"
            . '- unused: rename tables and columns without definition to "zzz_deleted_*".' . "\n"
            . '- obsolete: drop "zzz_deleted_*" tables and columns and indexes without definition. Data is lost.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());
        $levelOption = (string)$input->getOption('level');
        $level = SchemaLevel::tryFrom($levelOption);
        if ($level === null) {
            $io->error('Invalid level "' . $levelOption . '". Use -h for help on valid options.');
            return HealthCheckResult::Error->value;
        }

        $shown = [];
        $hidden = [];
        foreach ($this->schemaInspector->getChanges() as $change) {
            if ($this->schemaInspector->isInLevel($change->category, $level)) {
                $shown[] = $change;
            } else {
                $hidden[$change->category->value] = ($hidden[$change->category->value] ?? 0) + 1;
            }
        }
        $this->schemaChangesRenderer->render($io, $shown);
        if ($hidden !== []) {
            $counts = [];
            foreach ($hidden as $category => $count) {
                $counts[] = $count . ' ' . $category;
            }
            $io->text('Statements not shown with level "' . $level->value . '": ' . implode(', ', $counts) . '. Use "--level all" to show them.');
            $io->newLine();
        }
        if ($shown === []) {
            $io->success('Database schema is up to date with level "' . $level->value . '".');
            return HealthCheckResult::Ok->value;
        }
        $io->warning('Statements to update the database schema with level "' . $level->value . '": ' . count($shown) . '.');
        return HealthCheckResult::Broken->value;
    }
}
