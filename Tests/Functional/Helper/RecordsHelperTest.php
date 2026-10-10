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
use Lolli\Dbdoctor\Exception\UnexpectedNumberOfAffectedRowsException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

class RecordsHelperTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'lolli/dbdoctor',
    ];

    #[Test]
    public function getRecordThrowsExceptionWithEmptyFields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1647791187);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->get(RecordsHelper::class);
        $recordsHelper->getRecord(new PreparedStatements(), 'pages', [], 0);
    }

    #[Test]
    public function deleteMmRowsThrowsExceptionWithEmptyWhereFields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791484210);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->get(RecordsHelper::class);
        $recordsHelper->deleteMmRows(new PreparedStatements(), false, 'sys_category_record_mm', []);
    }

    #[Test]
    public function deleteMmRowsThrowsExceptionIfNoRowIsDeleted(): void
    {
        $this->expectException(UnexpectedNumberOfAffectedRowsException::class);
        $this->expectExceptionCode(1791484211);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->get(RecordsHelper::class);
        $recordsHelper->deleteMmRows(new PreparedStatements(), false, 'sys_category_record_mm', ['uid_local' => ['value' => 42, 'type' => Connection::PARAM_INT]]);
    }

    #[Test]
    public function deleteMmRowsReturnsSqlWithValues(): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->get(RecordsHelper::class);
        $sql = $recordsHelper->deleteMmRows(
            new PreparedStatements(),
            true,
            'sys_category_record_mm',
            [
                'uid_local' => ['value' => 42, 'type' => Connection::PARAM_INT],
                'tablenames' => ['value' => 'tt_content', 'type' => Connection::PARAM_STR],
            ]
        );
        self::assertStringContainsString('= 42', $sql);
        self::assertStringContainsString('= \'tt_content\'', $sql);
        self::assertStringNotContainsString('?', $sql);
    }
}
