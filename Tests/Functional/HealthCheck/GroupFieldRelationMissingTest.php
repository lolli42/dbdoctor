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
use Lolli\Dbdoctor\Exception\EarlierCheckNotFixedException;
use Lolli\Dbdoctor\HealthCheck\GroupFieldRelationMissing;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class GroupFieldRelationMissingTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
        __DIR__ . '/../FixtureExtensions/tx_dbdoctortestsgroup',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldRelationMissingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var GroupFieldRelationMissing $subject */
        $subject = $this->get(GroupFieldRelationMissing::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldRelationMissingImport.csv');
        /** @var GroupFieldRelationMissing $subject */
        $subject = $this->get(GroupFieldRelationMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldRelationMissingFixed.csv');
    }

    #[Test]
    public function checkModeSkipsCheckIfFileReferenceToMissingFileExists(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldRelationMissingFileReferenceImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var GroupFieldRelationMissing $subject */
        $subject = $this->get(GroupFieldRelationMissing::class);
        $io->expects(self::once())->method('warning')->with(self::callback(
            static fn(mixed $message): bool => is_array($message) && str_starts_with((string)$message[0], 'Check skipped')
        ));
        self::assertSame(HealthCheckInterface::RESULT_BROKEN, $subject->handle($io, HealthCheckInterface::MODE_CHECK, ''));
    }

    #[Test]
    public function executeModeThrowsIfFileReferenceToMissingFileExists(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldRelationMissingFileReferenceImport.csv');
        /** @var GroupFieldRelationMissing $subject */
        $subject = $this->get(GroupFieldRelationMissing::class);
        $this->expectException(EarlierCheckNotFixedException::class);
        $this->expectExceptionCode(1791633600);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
    }
}
