<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Tests\Unit\Helper;

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
use Lolli\Dbdoctor\Helper\PagesTreeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class PagesTreeHelperTest extends UnitTestCase
{
    public static function resolveClosestSeedDataProvider(): \Generator
    {
        yield 'pages connected to root seed 0' => [
            [1 => 0, 2 => 1, 3 => 2],
            [0 => true],
            [1 => true, 2 => true, 3 => true],
        ];
        yield 'sub page with lower uid than its parent' => [
            [1 => 0, 2 => 3, 3 => 1],
            [0 => true],
            [1 => true, 2 => true, 3 => true],
        ];
        yield 'pid of a not existing page' => [
            [1 => 0, 2 => 42, 3 => 2],
            [0 => true],
            [1 => true, 2 => null, 3 => null],
        ];
        yield 'pid loop' => [
            [1 => 0, 2 => 3, 3 => 2, 4 => 3],
            [0 => true],
            [1 => true, 2 => null, 3 => null, 4 => null],
        ];
        yield 'page itself is a seed' => [
            [1 => 0, 2 => 1, 3 => 2],
            [2 => 'b'],
            [1 => null, 2 => 'b', 3 => 'b'],
        ];
        yield 'closest seed wins' => [
            [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 1],
            [1 => 'a', 3 => 'c'],
            [1 => 'a', 2 => 'a', 3 => 'c', 4 => 'c', 5 => 'a'],
        ];
    }

    /**
     * @param array<int, int> $uidToPid
     * @param array<int, mixed> $seeds
     * @param array<int, mixed> $expected
     */
    #[Test]
    #[DataProvider('resolveClosestSeedDataProvider')]
    public function resolveClosestSeed(array $uidToPid, array $seeds, array $expected): void
    {
        $subject = new PagesTreeHelper(self::createStub(ConnectionPool::class));
        $result = $subject->resolveClosestSeed($uidToPid, $seeds);
        ksort($result);
        self::assertSame($expected, $result);
    }
}
