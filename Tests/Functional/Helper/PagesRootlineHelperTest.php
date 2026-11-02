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
use Lolli\Dbdoctor\Database\PreparedStatements;
use Lolli\Dbdoctor\Helper\PagesRootlineHelper;
use Lolli\Dbdoctor\Page\RootlinePage;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PagesRootlineHelperTest extends AbstractFunctionalTestCase
{
    public static function getRootlineDataProvider(): \Generator
    {
        yield 'pid 1' => [
            'pid' => 1,
            'expected' => [
                0 => new RootlinePage(0, 0, false, false, false, 0, 0, 'New TYPO3 site'),
                1 => new RootlinePage(1, 0, false, false, false, 1, 0, 'Ok site root'),
            ],
        ];
        yield 'pid 2' => [
            'pid' => 2,
            'expected' => [
                0 => new RootlinePage(0, 0, false, false, false, 0, 0, 'New TYPO3 site'),
                1 => new RootlinePage(1, 0, false, false, false, 1, 0, 'Ok site root'),
                2 => new RootlinePage(2, 1, false, false, false, 1, 0, 'Ok sub page 1'),
            ],
        ];
        yield 'pid 3' => [
            'pid' => 3,
            'expected' => [
                0 => new RootlinePage(0, 0, false, false, false, 0, 0, 'New TYPO3 site'),
                1 => new RootlinePage(1, 0, false, false, false, 1, 0, 'Ok site root'),
                2 => new RootlinePage(3, 1, false, false, false, 1, 0, 'Ok sub page 2'),
            ],
        ];
        yield 'pid 4' => [
            'pid' => 4,
            'expected' => [
                0 => new RootlinePage(4, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
            ],
        ];
        yield 'pid 5' => [
            'pid' => 5,
            'expected' => [
                0 => new RootlinePage(4, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
                1 => new RootlinePage(5, 4, false, false, false, 1, 0, 'Lost direct parent 1'),
            ],
        ];
        yield 'pid 6' => [
            'pid' => 6,
            'expected' => [
                0 => new RootlinePage(4, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
                1 => new RootlinePage(5, 4, false, false, false, 1, 0, 'Lost direct parent 1'),
                2 => new RootlinePage(6, 5, false, false, false, 1, 0, 'Sub page of not connected page'),
            ],
        ];
        yield 'pid 7' => [
            'pid' => 7,
            'expected' => [
                0 => new RootlinePage(7, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
            ],
        ];
        yield 'pid 8' => [
            'pid' => 8,
            'expected' => [
                0 => new RootlinePage(7, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
                1 => new RootlinePage(8, 7, false, false, false, 1, 0, 'Lost direct parent 2'),
            ],
        ];
        yield 'pid 9' => [
            'pid' => 9,
            'expected' => [
                0 => new RootlinePage(7, 0, true, false, false, 0, 0, 'RECORD DOES NOT EXIST'),
                1 => new RootlinePage(9, 7, false, false, false, 1, 1, 'WS page Lost direct parent'),
            ],
        ];
        yield 'pid 10' => [
            'pid' => 10,
            'expected' => [
                0 => new RootlinePage(11, 10, false, false, false, 1, 0, 'Not Ok loop to 10'),
                1 => new RootlinePage(10, 11, false, false, false, 1, 0, 'Not Ok loop to 11'),
            ],
        ];
        yield 'pid 11' => [
            'pid' => 11,
            'expected' => [
                0 => new RootlinePage(10, 11, false, false, false, 1, 0, 'Not Ok loop to 11'),
                1 => new RootlinePage(11, 10, false, false, false, 1, 0, 'Not Ok loop to 10'),
            ],
        ];
    }

    /**
     * @param list<RootlinePage> $expected
     */
    #[Test]
    #[DataProvider('getRootlineDataProvider')]
    public function getRootline(int $pid, array $expected): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagesBrokenTreeImport.csv');
        /** @var PagesRootlineHelper $subject */
        $subject = $this->get(PagesRootlineHelper::class);
        self::assertEquals($expected, $subject->getRootline(new PreparedStatements(), $pid));
    }
}
