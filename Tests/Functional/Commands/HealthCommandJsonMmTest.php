<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Commands;

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
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

class HealthCommandJsonMmTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
        __DIR__ . '/../FixtureExtensions/tx_dbdoctortestsgroup',
    ];

    #[Test]
    public function reportsMmFindingsWithWhereFields(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/GroupFieldMmRelationMissingImport.csv');
        $commandTester = new CommandTester($this->get(CommandRegistry::class)->get('dbdoctor:health'));
        $commandTester->execute([
            '--mode' => 'check',
            '--format' => 'json',
            '--scope' => 'all',
            '--limit' => '0',
            '--check' => ['GroupFieldMmRelationMissing'],
        ]);
        $report = json_decode($commandTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $check = $report['checks'][0];
        self::assertSame(['record-field', 'mm'], $check['keyTypes']);
        $keys = array_column($check['findings'], 'key');
        // Category 2 does not exist: Its MM rows are one finding without record
        $missingLocalKey = [
            'type' => 'mm',
            'mmTable' => 'sys_category_record_mm',
            'uidLocal' => 2,
            'localTable' => 'sys_category',
            'field' => 'items',
            'matchFields' => [],
        ];
        self::assertContains($missingLocalKey, $keys);
        $missingLocalFinding = $check['findings'][array_search($missingLocalKey, $keys, true)];
        self::assertNull($missingLocalFinding['record']);
        self::assertNull($missingLocalFinding['pid']);
        self::assertSame(['mmRowCount' => 2], $missingLocalFinding['evidence']);
        self::assertSame('mm-delete', $missingLocalFinding['writes'][0]['operation']);
        self::assertSame(['uid_local' => 2], $missingLocalFinding['writes'][0]['where']);
        // Category 1 relates to the missing tt_content 94: A finding on its field "items"
        $missingRelationKey = ['type' => 'record-field', 'table' => 'sys_category', 'uid' => 1, 'field' => 'items'];
        self::assertContains($missingRelationKey, $keys);
        $missingRelationFinding = $check['findings'][array_search($missingRelationKey, $keys, true)];
        self::assertSame(1, $missingRelationFinding['record']['uid']);
        self::assertSame(['uid_local' => 1, 'uid_foreign' => 94, 'tablenames' => 'tt_content'], $missingRelationFinding['writes'][0]['where']);
        self::assertFalse($report['tables']['sys_category_record_mm']['tca']);
        self::assertSame(5, $report['tables']['sys_category_record_mm']['rowCount']);
        // Item 1 has two broken fields: Two findings, but one affected row of the three items
        self::assertSame(['findings' => 3, 'rows' => 2, 'share' => 0.66667], $check['counts']['byTable']['tx_dbdoctortestsgroup_item']);
        // The missing category 2 is one finding with its two MM rows
        self::assertSame(['findings' => 1, 'rows' => 2, 'share' => 0.4], $check['counts']['byTable']['sys_category_record_mm']);
    }
}
