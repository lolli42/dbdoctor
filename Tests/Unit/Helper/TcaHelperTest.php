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

use Lolli\Dbdoctor\Helper\TcaHelper;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class TcaHelperTest extends UnitTestCase
{
    /**
     * @test
     */
    public function getNextWorkspaceEnabledTcaTableReturnsWorkspaceEnabledTcaTables(): void
    {
        $GLOBALS['TCA'] = [
            'workspaceEnabled1' => [
                'ctrl' => [
                    'versioningWS' => true,
                ],
            ],
            'workspaceEnabled2' => [
                'ctrl' => [
                    'versioningWS' => 1,
                ],
            ],
            'noWorkspace1' => [
                'ctrl' => [
                    'versioningWS' => false,
                ],
            ],
            'noWorkspace2' => [
                'ctrl' => [
                    'versioningWS' => 0,
                ],
            ],
            'noWorkspace3' => [
                'ctrl' => [],
            ],
            'workspaceEnabled3' => [
                'ctrl' => [
                    'versioningWS' => true,
                ],
            ],
        ];
        $subject = new TcaHelper();
        $result = [];
        foreach ($subject->getNextWorkspaceEnabledTcaTable() as $table) {
            $result[] = $table;
        }
        $expected = [
            'workspaceEnabled1',
            'workspaceEnabled2',
            'workspaceEnabled3',
        ];
        self::assertSame($expected, $result);
    }

    /**
     * @test
     */
    public function getNextLanguageAwareTcaTableReturnsLanguageAwareEnabledTcaTables(): void
    {
        $GLOBALS['TCA'] = [
            'languageAware1' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
            'notAware1' => [
                'ctrl' => [],
            ],
            'notAware2' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                ],
            ],
            'notAware3' => [
                'ctrl' => [
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
            'languageAware2' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
        ];
        $subject = new TcaHelper();
        $result = [];
        foreach ($subject->getNextLanguageAwareTcaTable() as $table) {
            $result[] = $table;
        }
        $expected = [
            'languageAware1',
            'languageAware2',
        ];
        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function hasFlexFieldDataProvider(): array
    {
        return [
            'fieldName empty' => [
                [],
                '',
                false,
            ],
            'table does not exist' => [
                [],
                'bar',
                false,
            ],
            'empty columns' => [
                [
                    'foo' => [
                        'ctrl' => [],
                        'columns' => [],
                    ],
                ],
                'foo',
                false,
            ],
            'no config' => [
                [
                    'foo' => [
                        'ctrl' => [],
                        'columns' => [
                            'field1' => [
                            ],
                        ],
                    ],
                ],
                'foo',
                false,
            ],
            'no type' => [
                [
                    'foo' => [
                        'ctrl' => [],
                        'columns' => [
                            'field1' => [
                                'config' => [

                                ],
                            ],
                        ],
                    ],
                ],
                'foo',
                false,
            ],
            'no flex type' => [
                [
                    'foo' => [
                        'ctrl' => [],
                        'columns' => [
                            'field1' => [
                                'config' => [
                                    'type' => 'input',
                                ],
                            ],
                        ],
                    ],
                ],
                'foo',
                false,
            ],
            'one flex type' => [
                [
                    'foo' => [
                        'ctrl' => [],
                        'columns' => [
                            'field1' => [
                                'config' => [
                                    'type' => 'flex',
                                ],
                            ],
                        ],
                    ],
                ],
                'foo',
                true,
            ],
        ];
    }

    /**
     * @test
     * @dataProvider hasFlexFieldDataProvider
     * @param array<mixed> $tca
     */
    public function hasFlexField(array $tca, string $fieldName, bool $result): void
    {
        $GLOBALS['TCA'] = $tca;
        self::assertSame($result, (new TcaHelper())->hasFlexField($fieldName));
    }

    /**
     * @test
     */
    public function getFieldNameByCtrlNameReturnsName(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['bar'] = 'baz';
        self::assertSame('baz', (new TcaHelper())->getFieldNameByCtrlName('foo', 'bar'));
    }

    /**
     * @test
     */
    public function getFieldNameByCtrlNameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1646162580);
        (new TcaHelper())->getFieldNameByCtrlName('foo', 'bar');
    }

    /**
     * @test
     */
    public function getDeletedFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['delete'] = 'deletedField';
        self::assertSame('deletedField', (new TcaHelper())->getDeletedField('foo'));
    }

    /**
     * @test
     */
    public function getDeletedFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getDeletedField('foo'));
    }

    /**
     * @test
     */
    public function getCreateUserIdFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['cruser_id'] = 'cruserIdField';
        self::assertSame('cruserIdField', (new TcaHelper())->getCreateUserIdField('foo'));
    }

    /**
     * @test
     */
    public function getCreateUserIdFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getCreateUserIdField('foo'));
    }

    /**
     * @test
     */
    public function getTimestampFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['tstamp'] = 'tstampField';
        self::assertSame('tstampField', (new TcaHelper())->getTimestampField('foo'));
    }

    /**
     * @test
     */
    public function getTimestampFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTimestampField('foo'));
    }

    /**
     * @test
     */
    public function getLanguageFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['languageField'] = 'langField';
        self::assertSame('langField', (new TcaHelper())->getLanguageField('foo'));
    }

    /**
     * @test
     */
    public function getLanguageFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getLanguageField('foo'));
    }

    /**
     * @test
     */
    public function getTranslationParentFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['transOrigPointerField'] = 'l10n_parent';
        self::assertSame('l10n_parent', (new TcaHelper())->getTranslationParentField('foo'));
    }

    /**
     * @test
     */
    public function getTranslationParentFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTranslationParentField('foo'));
    }

    /**
     * @test
     */
    public function getWorkspaceIdFieldReturnsFieldWithTrue(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = true;
        self::assertSame('t3ver_wsid', (new TcaHelper())->getWorkspaceIdField('foo'));
    }

    /**
     * @test
     */
    public function getWorkspaceIdFieldReturnsFieldWithOne(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = 1;
        self::assertSame('t3ver_wsid', (new TcaHelper())->getWorkspaceIdField('foo'));
    }

    /**
     * @test
     */
    public function getWorkspaceFieldReturnsNullWithNull(): void
    {
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    /**
     * @test
     */
    public function getWorkspaceFieldReturnsNullWithFalse(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = false;
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    /**
     * @test
     */
    public function getWorkspaceFieldReturnsNullWithZero(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = 0;
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    /**
     * @test
     */
    public function getTypeFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['type'] = 'typeField';
        self::assertSame('typeField', (new TcaHelper())->getTypeField('foo'));
    }

    /**
     * @test
     */
    public function getTypeFieldReturnsNullForColonTypes(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['type'] = 'foo:bar';
        self::assertNull((new TcaHelper())->getTypeField('foo'));
    }

    /**
     * @test
     */
    public function getTypeFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTypeField('foo'));
    }
    /**
     * @test
     */
    public function getLabelFieldsReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getLabelFields('foo'));
    }

    /**
     * @test
     */
    public function getLabelFieldsReturnsLabel(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label'] = 'labelField';
        self::assertSame(['labelField'], (new TcaHelper())->getLabelFields('foo'));
    }

    /**
     * @test
     */
    public function getLabelFieldsReturnsLabelAlt(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label_alt'] = 'field1, field2, , field3 ';
        self::assertSame(['field1', 'field2', 'field3'], (new TcaHelper())->getLabelFields('foo'));
    }

    /**
     * @test
     */
    public function getLabelFieldsReturnsCombinedLabelAndLabelAlt(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label'] = 'labelField';
        $GLOBALS['TCA']['foo']['ctrl']['label_alt'] = 'labelAlt1, labelAlt2';
        self::assertSame(['labelField', 'labelAlt1', 'labelAlt2'], (new TcaHelper())->getLabelFields('foo'));
    }
}
