<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Functional\Helper;

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
use Lolli\Dbdoctor\Helper\PagesRootlineHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class PagesRootlineHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    public static function getRootlineDataProvider(): \Generator
    {
        yield 'pid 1' => [
            'pid' => 1,
            'expected' => [
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
            ],
        ];
        yield 'pid 2' => [
            'pid' => 2,
            'expected' => [
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
            ],
        ];
        yield 'pid 3' => [
            'pid' => 3,
            'expected' => [
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
            ],
        ];
        yield 'pid 4' => [
            'pid' => 4,
            'expected' => [
                0 => [
                    '_isMissing' => true,
                    'uid' => 4,
                    'pid' => 0,
                    'deleted' => true,
                    't3ver_wsid' => 0,
                    'title' => 'RECORD DOES NOT EXIST',
                ],
            ],
        ];
        yield 'pid 5' => [
            'pid' => 5,
            'expected' => [
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
            ],
        ];
        yield 'pid 6' => [
            'pid' => 6,
            'expected' => [
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
            ],
        ];
        yield 'pid 7' => [
            'pid' => 7,
            'expected' => [
                0 => [
                    '_isMissing' => true,
                    'uid' => 7,
                    'pid' => 0,
                    'deleted' => true,
                    't3ver_wsid' => 0,
                    'title' => 'RECORD DOES NOT EXIST',
                ],
            ],
        ];
        yield 'pid 8' => [
            'pid' => 8,
            'expected' => [
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
            ],
        ];
        yield 'pid 9' => [
            'pid' => 9,
            'expected' => [
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
            ],
        ];
        yield 'pid 10' => [
            'pid' => 10,
            'expected' => [
                0 => [
                    '_isMissing' => false,
                    'uid' => 11,
                    'pid' => 10,
                    'deleted' => false,
                    't3ver_wsid' => 0,
                    'title' => 'Not Ok loop to 10',
                ],
                1 => [
                    '_isMissing' => false,
                    'uid' => 10,
                    'pid' => 11,
                    'deleted' => 0,
                    't3ver_wsid' => 0,
                    'title' => 'Not Ok loop to 11',
                ],
            ],
        ];
        yield 'pid 11' => [
            'pid' => 11,
            'expected' => [
                0 => [
                    '_isMissing' => false,
                    'uid' => 10,
                    'pid' => 11,
                    'deleted' => 0,
                    't3ver_wsid' => 0,
                    'title' => 'Not Ok loop to 11',
                ],
                1 => [
                    '_isMissing' => false,
                    'uid' => 11,
                    'pid' => 10,
                    'deleted' => false,
                    't3ver_wsid' => 0,
                    'title' => 'Not Ok loop to 10',
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, int|bool|string>> $expected
     */
    #[Test]
    #[DataProvider('getRootlineDataProvider')]
    public function getRootline(int $pid, array $expected): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesBrokenTreeImport.csv');
        /** @var PagesRootlineHelper $subject */
        $subject = $this->get(PagesRootlineHelper::class);
        self::assertEquals($expected, $subject->getRootline($pid));
    }
}
