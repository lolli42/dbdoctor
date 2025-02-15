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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RecordsHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/dbdoctor',
    ];

    #[Test]
    public function getRecordThrowsExceptionWithEmptyFields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1647791187);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->get(RecordsHelper::class);
        $recordsHelper->getRecord('pages', [], 0);
    }
}
