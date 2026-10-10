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
use Lolli\Dbdoctor\HealthCheck\GroupFieldMmRelationMissing;
use Lolli\Dbdoctor\HealthCheck\HealthCheckInterface;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Style\SymfonyStyle;

class GroupFieldMmRelationMissingTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
        __DIR__ . '/../FixtureExtensions/tx_dbdoctortestsgroup',
    ];

    #[Test]
    public function showDetails(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingImport.csv');
        $io = $this->createMock(SymfonyStyle::class);
        /** @var GroupFieldMmRelationMissing $subject */
        $subject = $this->get(GroupFieldMmRelationMissing::class);
        $io->expects(self::atLeastOnce())->method('warning');
        $io->expects(self::atLeastOnce())->method('ask')->willReturn('p', 'd', 'a');
        $subject->handle($io, HealthCheckInterface::MODE_INTERACTIVE, '');
    }

    #[Test]
    public function fixBrokenRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingImport.csv');
        /** @var GroupFieldMmRelationMissing $subject */
        $subject = $this->get(GroupFieldMmRelationMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingFixed.csv');
    }

    #[Test]
    public function rowsOfMissingLocalRecordAreKeptIfMmTableIsUsedByFieldsOfMultipleTables(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingAmbiguousImport.csv');
        // uid_local of the MM table may now point to a tt_content record as well. The field has no
        // database column, the check does not scan it, but it makes the local side ambiguous.
        $GLOBALS['TCA']['tt_content']['columns']['dbdoctor_shared_mm'] = [
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'MM' => 'tx_dbdoctortestsgroup_item_mm',
            ],
        ];
        /** @var GroupFieldMmRelationMissing $subject */
        $subject = $this->get(GroupFieldMmRelationMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingAmbiguousImport.csv');
    }

    #[Test]
    public function fieldWithoutMatchFieldsIsSkippedIfMmTableIsSharedWithOtherFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingSharedImport.csv');
        // Broken TCA: The field reads rows of "relations_mm" and "relations_mm_single" as its own.
        $GLOBALS['TCA']['tx_dbdoctortestsgroup_item']['columns']['relations_mm_without_match_fields'] = [
            'config' => [
                'type' => 'group',
                'allowed' => 'tt_content',
                'MM' => 'tx_dbdoctortestsgroup_item_mm',
            ],
        ];
        /** @var GroupFieldMmRelationMissing $subject */
        $subject = $this->get(GroupFieldMmRelationMissing::class);
        $subject->handle(self::createStub(SymfonyStyle::class), HealthCheckInterface::MODE_EXECUTE, '');
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingSharedFixed.csv');
    }
}
