<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Commands;

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

use Lolli\Dbhealth\Health\HealthDeleteInterface;
use Lolli\Dbhealth\Health\HealthFactoryInterface;
use Lolli\Dbhealth\Health\HealthInterface;
use Lolli\Dbhealth\Health\HealthUpdateInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
            $result |= $healthInstance->handle($io, $mode);
            if (($result & HealthInterface::RESULT_ABORT) === HealthInterface::RESULT_ABORT) {
                $io->warning('Aborting ...');
                return $result;
            }
        }
        return $result;
    }
}
