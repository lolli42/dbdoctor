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
        $this->addOption('simulate', null, null, 'Non interactively go through all checks and simulate queries');
        $this->setHelp('This is an interactive command to go through a list of database health checks finding inconsistencies.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $isSimulate = (bool)$input->getOption('simulate');
        if ($isSimulate) {
            $io->warning('Simulate mode. Just checking and outputting queries that would be executed.');
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
            $newResult = $healthInstance->handle($io, $isSimulate);
            if ($newResult === HealthInterface::RESULT_ABORT) {
                $io->warning('Aborting ...');
                return $newResult;
            }
            $result |= $newResult;
        }
        return $result;
    }
}
