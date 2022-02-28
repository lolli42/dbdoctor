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

use Lolli\Dbhealth\Health\HealthFactoryInterface;
use Lolli\Dbhealth\Health\HealthInterface;
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
        $this->setHelp('More help here');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        foreach ($this->healthFactory->getNext() as $healthInstance) {
            $healthInstance->header($io);
            $result = $healthInstance->process($io, $this);
            if ($result === HealthInterface::RESULT_ABORT) {
                $io->warning('Aborting ...');
                break;
            }
        }

        return 0;
    }
}
