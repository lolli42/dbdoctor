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

use Lolli\Dbdoctor\Health\HealthDeleteInterface;
use Lolli\Dbdoctor\Health\HealthFactoryInterface;
use Lolli\Dbdoctor\Health\HealthInterface;
use Lolli\Dbdoctor\Health\HealthUpdateInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Main CLI entry point
 */
class HealthCommand extends Command
{
    private HealthFactoryInterface $healthFactory;

    public function __construct(HealthFactoryInterface $healthFactory)
    {
        $this->healthFactory = $healthFactory;
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_OPTIONAL,
            'interactive|check|execute - check: run all checks but no DB changes, execute: blindly execute all changes (!)',
            'interactive'
        );
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_OPTIONAL,
            'log execute queries to a given absolute file'
        );
        $this->setHelp('This is an interactive command to go through a list of database health checks finding inconsistencies.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $mode = (string)$input->getOption('mode'); /** @phpstan-ignore-line */
        if ($mode === 'interactive') {
            $mode = HealthInterface::MODE_INTERACTIVE;
        } elseif ($mode === 'check') {
            $mode = HealthInterface::MODE_CHECK;
        } elseif ($mode === 'execute') {
            $mode = HealthInterface::MODE_EXECUTE;
        } else {
            $io->error('Invalid mode "' . $mode . '". Use -h for help on valid options.');
            return HealthInterface::RESULT_ERROR;
        }

        $file = (string)$input->getOption('file'); /** @phpstan-ignore-line */
        if ($file && $mode === HealthInterface::MODE_CHECK) {
            $io->error('Option "--file" not available with "--mode check"');
            return HealthInterface::RESULT_ERROR;
        }
        if ($file) {
            if (!PathUtility::isAbsolutePath($file)) {
                $io->error('Invalid file "' . $file . '". Must be an absolute path.');
                return HealthInterface::RESULT_ERROR;
            }
            if (file_exists($file)) {
                $io->error('Invalid file "' . $file . '". File exists already.');
                return HealthInterface::RESULT_ERROR;
            }
            $touchFile = file_put_contents($file, '');
            if ($touchFile === false) {
                $io->error('Invalid file "' . $file . '". Unable to create.');
                return HealthInterface::RESULT_ERROR;
            }
        }

        $result = HealthInterface::RESULT_OK;
        foreach ($this->healthFactory->getNext() as $healthInstance) {
            /** @var HealthInterface $healthInstance */
            if (!$healthInstance instanceof HealthInterface) {
                throw new \RuntimeException('Single health checks must implement HealthInterface', 1646321959);
            }
            if ((!$healthInstance instanceof HealthDeleteInterface && !$healthInstance instanceof HealthUpdateInterface)
                || ($healthInstance instanceof HealthDeleteInterface && $healthInstance instanceof HealthUpdateInterface)
            ) {
                throw new \RuntimeException(
                    'Single health checks must either implement HealthDeleteInterface or HealthUpdateInterface',
                    1646322037
                );
            }
            $healthInstance->header($io);
            $result |= $healthInstance->handle($io, $mode, $file);
            if (($result & HealthInterface::RESULT_ABORT) === HealthInterface::RESULT_ABORT) {
                $io->warning('Aborting ...');
                $result |= $this->removeEmptyFile($io, $file);
                return $result;
            }
        }
        $result |= $this->removeEmptyFile($io, $file);
        return $result;
    }

    /**
     * There is no point in keeping an empty sql file.
     * File position has been validated before.
     */
    private function removeEmptyFile(SymfonyStyle $io, string $file): int
    {
        if (empty($file)) {
            return HealthInterface::RESULT_OK;
        }
        $result = true;
        if (filesize($file) === 0) {
            $result = unlink($file);
        }
        if (!$result) {
            $io->error('Unable to remove empty file "' . $file . '".');
            return HealthInterface::RESULT_ERROR;
        }
        return HealthInterface::RESULT_OK;
    }
}
