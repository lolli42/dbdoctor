<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Tests\Functional\Helper;

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

use Lolli\Dbhealth\Helper\PagesRootlineHelper;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class PagesRootlineHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbhealth',
    ];

    /**
     * @test
     */
    public function isInRootline(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesBrokenTreeImport.csv');
        /** @var PagesRootlineHelper $subject */
        $subject = $this->get(PagesRootlineHelper::class);
        self::assertTrue($subject->isInRootline(1));
        self::assertTrue($subject->isInRootline(2));
        self::assertTrue($subject->isInRootline(3));
        self::assertFalse($subject->isInRootline(4));
        self::assertFalse($subject->isInRootline(5));
        self::assertFalse($subject->isInRootline(6));
        self::assertFalse($subject->isInRootline(7));
        self::assertFalse($subject->isInRootline(8));
        self::assertFalse($subject->isInRootline(9));
    }

    /**
     * @test
     */
    public function getRootline(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesBrokenTreeImport.csv');
        /** @var PagesRootlineHelper $subject */
        $subject = $this->get(PagesRootlineHelper::class);
        $expected = [
            0 => [
                '_isMissing' => false,
                'uid' => 0,
                'pid' => 0,
                'deleted' => false,
                't3ver_wsid' => 0,
                'title' => 'New TYPO3 site',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 1,
                'pid' => 0,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Ok site root',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(1));

        $expected = [
            0 => [
                '_isMissing' => false,
                'uid' => 0,
                'pid' => 0,
                'deleted' => false,
                't3ver_wsid' => 0,
                'title' => 'New TYPO3 site',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 1,
                'pid' => 0,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Ok site root',
            ],
            2 => [
                '_isMissing' => false,
                'uid' => 2,
                'pid' => 1,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Ok sub page 1',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(2));

        $expected = [
            0 => [
                '_isMissing' => false,
                'uid' => 0,
                'pid' => 0,
                'deleted' => false,
                't3ver_wsid' => 0,
                'title' => 'New TYPO3 site',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 1,
                'pid' => 0,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Ok site root',
            ],
            2 => [
                '_isMissing' => false,
                'uid' => 3,
                'pid' => 1,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Ok sub page 2',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(3));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 4,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(4));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 4,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 5,
                'pid' => 4,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Lost direct parent 1',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(5));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 4,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 5,
                'pid' => 4,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Lost direct parent 1',
            ],
            2 => [
                '_isMissing' => false,
                'uid' => 6,
                'pid' => 5,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Sub page of not connected page',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(6));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 7,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(7));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 7,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 8,
                'pid' => 7,
                'deleted' => 0,
                't3ver_wsid' => 0,
                'title' => 'Lost direct parent 2',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(8));

        $expected = [
            0 => [
                '_isMissing' => true,
                'uid' => 7,
                'pid' => 0,
                'deleted' => true,
                't3ver_wsid' => 0,
                'title' => 'RECORD DOES NOT EXIST',
            ],
            1 => [
                '_isMissing' => false,
                'uid' => 9,
                'pid' => 7,
                'deleted' => 0,
                't3ver_wsid' => 1,
                'title' => 'WS page Lost direct parent',
            ],
        ];
        self::assertEquals($expected, $subject->getRootline(9));
    }
}
