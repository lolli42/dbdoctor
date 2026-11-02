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
use Lolli\Dbdoctor\Tca\ForeignTableListField;
use Lolli\Dbdoctor\Tca\GroupField;
use Lolli\Dbdoctor\Tca\InlineForeignFieldChild;
use Lolli\Dbdoctor\Tca\InlineForeignFieldNoForeignTableFieldChild;
use Lolli\Dbdoctor\Tca\InlineForeignFieldNoForeignTableFieldParent;
use Lolli\Dbdoctor\Tca\MmField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class TcaHelperTest extends UnitTestCase
{
    #[Test]
    public function getNextTcaTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $tables = [];
        $subject = new TcaHelper();
        foreach ($subject->getNextTcaTable() as $item) {
            $tables[] = $item;
        }
    }

    #[Test]
    public function getNextTcaTableReturnsNothingWithSomeTcaTables(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [],
            'bar' => [],
        ];
        $subject = new TcaHelper();
        $tables = [];
        foreach ($subject->getNextTcaTable() as $item) {
            $tables[] = $item;
        }
        self::assertSame(['foo', 'bar'], $tables);
    }

    #[Test]
    public function getNextTcaTableIgnoresTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [],
            'bar' => [],
        ];
        $subject = new TcaHelper();
        $tables = [];
        foreach ($subject->getNextTcaTable(['foo']) as $item) {
            $tables[] = $item;
        }
        self::assertSame(['bar'], $tables);
    }

    #[Test]
    public function getNextSoftDeleteAwareTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextSoftDeleteAwareTable() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
    public function getNextSoftDeleteAwareTableReturnsTableNames(): void
    {
        $GLOBALS['TCA'] = [
            'noCtrl' => [
            ],
            'noDeleteInCtrl' => [
                'ctrl' => [
                ],
            ],
            'deleteFalse' => [
                'ctrl' => [
                    'delete' => false,
                ],
            ],
            'deleteTrue' => [
                'ctrl' => [
                    'delete' => true,
                ],
            ],
            'deleteArray' => [
                'ctrl' => [
                    'delete' => [],
                ],
            ],
            'deleteEmptyString' => [
                'ctrl' => [
                    'delete' => '',
                ],
            ],
            'correctDelete' => [
                'ctrl' => [
                    'delete' => 'deleted',
                ],
            ],
        ];
        $tableNames = [];
        $subject = new TcaHelper();
        foreach ($subject->getNextSoftDeleteAwareTable() as $tableName) {
            $tableNames[] = $tableName;
        }
        self::assertSame(['correctDelete'], $tableNames);
    }

    #[Test]
    public function getNextWorkspaceEnabledTcaTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextWorkspaceEnabledTcaTable() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
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

    #[Test]
    public function getNextLanguageAwareTcaTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextLanguageAwareTcaTable() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
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

    #[Test]
    public function getNextLanguageAwareTcaTableIgnoresTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
            'bar' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
        ];
        $subject = new TcaHelper();
        $tables = [];
        foreach ($subject->getNextLanguageAwareTcaTable(['foo']) as $item) {
            $tables[] = $item;
        }
        self::assertSame(['bar'], $tables);
    }

    #[Test]
    public function getNextLanguageSourceAwareTcaTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextLanguageSourceAwareTcaTable() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
    public function getNextLanguageSourceAwareTcaTableReturnsLanguageSourceAwareEnabledTcaTables(): void
    {
        $GLOBALS['TCA'] = [
            'languageAware1' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                    'translationSource' => 'l10n_source',
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
            'notAware4' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                ],
            ],
            'notAware5' => [
                'ctrl' => [
                    'transOrigPointerField' => 'l18n_parent',
                    'translationSource' => 'l10n_source',
                ],
            ],
            'notAware6' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'translationSource' => 'l10n_source',
                ],
            ],
        ];
        $subject = new TcaHelper();
        $result = [];
        foreach ($subject->getNextLanguageSourceAwareTcaTable() as $table) {
            $result[] = $table;
        }
        $expected = [
            'languageAware1',
        ];
        self::assertSame($expected, $result);
    }

    #[Test]
    public function getNextLanguageSourceAwareTcaTableIgnoresTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                    'translationSource' => 'l10n_source',
                ],
            ],
            'bar' => [
                'ctrl' => [
                    'languageField' => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                    'translationSource' => 'l10n_source',
                ],
            ],
        ];
        $subject = new TcaHelper();
        $tables = [];
        foreach ($subject->getNextLanguageSourceAwareTcaTable(['foo']) as $item) {
            $tables[] = $item;
        }
        self::assertSame(['bar'], $tables);
    }

    #[Test]
    public function getNextInlineForeignFieldChildTcaTableThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextInlineForeignFieldChildTcaTable() as $item) {
            // Trigger iterable
        }
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function getNextInlineForeignFieldChildTcaTableDataProvider(): iterable
    {
        yield 'empty' => [
            [],
            [],
            [],
        ];

        yield 'one child, incomplete parent' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [],
            [],
        ];

        yield 'one child, incomplete child' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [],
            [],
        ];

        yield 'one child' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldChild('child_table', 'parent_uid', 'parent_table_name_field'),
            ],
            [],
        ];

        yield 'two children' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_2',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
                'child_table_2' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldChild('child_table_1', 'parent_uid', 'parent_table_name_field'),
                new InlineForeignFieldChild('child_table_2', 'parent_uid', 'parent_table_name_field'),
            ],
            [],
        ];

        yield 'two children, one ignored' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_2',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
                'child_table_2' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldChild('child_table_2', 'parent_uid', 'parent_table_name_field'),
            ],
            ['child_table_1'],
        ];

        yield 'one child, referenced twice from same parent table' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldChild('child_table_1', 'parent_uid', 'parent_table_name_field'),
            ],
            [],
        ];

        yield 'one child, referenced twice from different parent tables' => [
            [
                'parent_table_1' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'parent_table_2' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                                'foreign_table_field' => 'parent_table_name_field',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'parent_table_name_field' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldChild('child_table_1', 'parent_uid', 'parent_table_name_field'),
            ],
            [],
        ];
    }

    /**
     * @param array<mixed> $tca
     * @param array<mixed> $expected
     * @param array<int, string> $ignoredTables
     */
    #[DataProvider('getNextInlineForeignFieldChildTcaTableDataProvider')]
    #[Test]
    public function getNextInlineForeignFieldChildTcaTable(array $tca, array $expected, array $ignoredTables): void
    {
        $GLOBALS['TCA'] = $tca;
        $subject = new TcaHelper();
        $result = [];
        foreach ($subject->getNextInlineForeignFieldChildTcaTable($ignoredTables) as $item) {
            $result[] = $item;
        }
        self::assertEquals($expected, $result);
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function getNextInlineForeignFieldNoForeignTableFieldChildTcaTableDataProvider(): iterable
    {
        yield 'empty' => [
            [],
            [],
            [],
        ];

        yield 'one child, incomplete parent' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [],
            [],
        ];

        yield 'one child, incomplete child' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                    ],
                ],
            ],
            [],
            [],
        ];

        yield 'one child' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldNoForeignTableFieldChild('child_table', 'parent_table', 'parent_uid'),
            ],
            [],
        ];

        yield 'two children' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_2',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
                'child_table_2' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldNoForeignTableFieldChild('child_table_1', 'parent_table', 'parent_uid'),
                new InlineForeignFieldNoForeignTableFieldChild('child_table_2', 'parent_table', 'parent_uid'),
            ],
            [],
        ];

        yield 'two children, one ignored' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_2',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
                'child_table_2' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldNoForeignTableFieldChild('child_table_2', 'parent_table', 'parent_uid'),
            ],
            ['child_table_1'],
        ];

        yield 'one child, referenced twice from same parent table' => [
            [
                'parent_table' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                        'inline_parent_2' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldNoForeignTableFieldChild('child_table_1', 'parent_table', 'parent_uid'),
            ],
            [],
        ];

        yield 'one child, referenced twice from different parent tables' => [
            [
                'parent_table_1' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'parent_table_2' => [
                    'columns' => [
                        'inline_parent_1' => [
                            'config' => [
                                'type' => 'inline',
                                'foreign_table' => 'child_table_1',
                                'foreign_field' => 'parent_uid',
                            ],
                        ],
                    ],
                ],
                'child_table_1' => [
                    'columns' => [
                        'parent_uid' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
            [
                new InlineForeignFieldNoForeignTableFieldChild('child_table_1', 'parent_table_1', 'parent_uid'),
            ],
            [],
        ];
    }

    /**
     * @param array<mixed> $tca
     * @param array<mixed> $expected
     * @param array<int, string> $ignoredTables
     */
    #[DataProvider('getNextInlineForeignFieldNoForeignTableFieldChildTcaTableDataProvider')]
    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldChildTcaTable(array $tca, array $expected, array $ignoredTables): void
    {
        $GLOBALS['TCA'] = $tca;
        $subject = new TcaHelper();
        $result = [];
        foreach ($subject->getNextInlineForeignFieldNoForeignTableFieldChildTcaTable($ignoredTables) as $item) {
            $result[] = $item;
        }
        self::assertEquals($expected, $result);
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false);
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentReturnsParentWithoutMatchFields(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table' => [
                'columns' => [
                    'children' => self::getInlineColumn([]),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        $expected = [
            new InlineForeignFieldNoForeignTableFieldParent('child_table', 'parent_table', 'parent_uid', []),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentReturnsParentFieldsOfSameTableOnce(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table' => [
                'columns' => [
                    'children_1' => self::getInlineColumn([]),
                    'children_2' => self::getInlineColumn([]),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        $expected = [
            new InlineForeignFieldNoForeignTableFieldParent('child_table', 'parent_table', 'parent_uid', []),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentSkipsParentTablesWithoutMatchFields(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => self::getInlineColumn([]),
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'children' => self::getInlineColumn([]),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        self::assertSame([], iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentSkipsParentTablesWithOverlappingMatchFields(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_field' => 'children']),
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_table' => 'parent_table_2']),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        self::assertSame([], iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentReturnsParentTablesWithDisjointMatchFields(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_table' => 'parent_table_1', 'parent_field' => 'children']),
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_table' => 'parent_table_2']),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        $expected = [
            new InlineForeignFieldNoForeignTableFieldParent('child_table', 'parent_table_1', 'parent_uid', ['parent_table' => 'parent_table_1', 'parent_field' => 'children']),
            new InlineForeignFieldNoForeignTableFieldParent('child_table', 'parent_table_2', 'parent_uid', ['parent_table' => 'parent_table_2']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentReturnsParentDisjointFromParentWithForeignTableField(): void
    {
        $parentWithForeignTableField = self::getInlineColumn(['parent_field' => 'children']);
        $parentWithForeignTableField['config']['foreign_table_field'] = 'parent_table';
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => $parentWithForeignTableField,
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_table' => 'parent_table_2']),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        $expected = [
            new InlineForeignFieldNoForeignTableFieldParent('child_table', 'parent_table_2', 'parent_uid', ['parent_table' => 'parent_table_2']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentSkipsParentOverlappingParentWithForeignTableField(): void
    {
        $parentWithForeignTableField = self::getInlineColumn([]);
        $parentWithForeignTableField['config']['foreign_table_field'] = 'parent_table';
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => $parentWithForeignTableField,
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'children' => self::getInlineColumn([]),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        self::assertSame([], iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    #[Test]
    public function getNextInlineForeignFieldNoForeignTableFieldParentSkipsMatchFieldWithoutTca(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table' => [
                'columns' => [
                    'children' => self::getInlineColumn(['not_in_tca' => 'parent_table']),
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        self::assertSame([], iterator_to_array((new TcaHelper())->getNextInlineForeignFieldNoForeignTableFieldParent(), false));
    }

    /**
     * @param array<string, string> $matchFields
     * @return array<string, mixed>
     */
    private static function getInlineColumn(array $matchFields): array
    {
        $column = [
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'child_table',
                'foreign_field' => 'parent_uid',
            ],
        ];
        if ($matchFields !== []) {
            $column['config']['foreign_match_fields'] = $matchFields;
        }
        return $column;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getInlineChildTca(): array
    {
        return [
            'columns' => [
                'parent_uid' => [
                    'config' => [
                        'type' => 'passthrough',
                    ],
                ],
                'parent_table' => [
                    'config' => [
                        'type' => 'passthrough',
                    ],
                ],
                'parent_field' => [
                    'config' => [
                        'type' => 'passthrough',
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function getNextGroupFieldWithoutMmThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextGroupFieldWithoutMm() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
    public function getNextGroupFieldWithoutMmReturnsGroupFields(): void
    {
        $GLOBALS['TCA'] = self::getGroupFieldTca();
        $result = [];
        foreach ((new TcaHelper())->getNextGroupFieldWithoutMm() as $item) {
            $result[] = $item;
        }
        $expected = [
            new GroupField('foo', 'csv', ['pages', 'tt_content']),
        ];
        self::assertEquals($expected, $result);
    }

    #[Test]
    public function getNextGroupFieldWithoutMmSkipsFieldsPointingToInlineParent(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table' => [
                'columns' => [
                    'children' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'child_table',
                            'foreign_field' => 'parent_uid',
                            'foreign_table_field' => 'parent_table',
                        ],
                    ],
                ],
            ],
            'child_table' => [
                'columns' => [
                    'parent_uid' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'tt_content',
                        ],
                    ],
                    'parent_table' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'tt_content',
                        ],
                    ],
                    'related' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'tt_content',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new GroupField('child_table', 'related', ['tt_content']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextGroupFieldWithoutMm(), false));
    }

    #[Test]
    public function getNextGroupFieldWithoutMmReturnsAllowedTablesByType(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'type' => 'record_type',
                ],
                'columns' => [
                    'record_type' => [
                        'config' => [
                            'type' => 'select',
                        ],
                    ],
                    'csv' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                        ],
                    ],
                ],
                'palettes' => [
                    'relations' => [
                        'showitem' => 'record_type, --linebreak--, csv;Label',
                    ],
                ],
                'types' => [
                    'noOverride' => [
                        'showitem' => 'record_type, csv',
                    ],
                    'shown' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'tt_content, pages']],
                        ],
                    ],
                    'shownWithLabel' => [
                        'showitem' => '--div--;Tab, record_type, csv;Label',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'tt_content']],
                        ],
                    ],
                    'shownInPalette' => [
                        'showitem' => '--palette--;Label;relations',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'sys_category']],
                        ],
                    ],
                    'notShown' => [
                        'showitem' => 'record_type',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'tt_content']],
                        ],
                    ],
                    'sameAllowed' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'pages', 'size' => 5]],
                        ],
                    ],
                    'labelOnly' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['label' => 'Label'],
                        ],
                    ],
                    'emptyAllowed' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => '']],
                        ],
                    ],
                    'typeChanged' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['type' => 'input']],
                        ],
                    ],
                    'mmAdded' => [
                        'showitem' => 'record_type, csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['MM' => 'foo_mm']],
                        ],
                    ],
                ],
            ],
        ];
        $result = iterator_to_array((new TcaHelper())->getNextGroupFieldWithoutMm(), false);
        self::assertCount(1, $result);
        self::assertSame('foo', $result[0]->tableName);
        self::assertSame('csv', $result[0]->fieldName);
        self::assertSame(['pages'], $result[0]->allowedTables);
        self::assertSame('record_type', $result[0]->typeField);
        $expectedAllowedTablesByType = [
            'shown' => ['tt_content', 'pages'],
            'shownWithLabel' => ['tt_content'],
            'shownInPalette' => ['sys_category'],
            'emptyAllowed' => null,
            'typeChanged' => null,
            'mmAdded' => null,
        ];
        self::assertSame($expectedAllowedTablesByType, $result[0]->allowedTablesByType);
    }

    #[Test]
    public function getNextGroupFieldWithoutMmIgnoresColumnsOverridesWithoutTypeField(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'columns' => [
                    'csv' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                        ],
                    ],
                ],
                'types' => [
                    '0' => [
                        'showitem' => 'csv',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'tt_content']],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new GroupField('foo', 'csv', ['pages']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextGroupFieldWithoutMm(), false));
    }

    #[Test]
    public function getNextGroupFieldWithoutMmSkipsFieldWithChangedAllowedIfTypeIsDefinedInForeignTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'type' => 'parent:record_type',
                ],
                'columns' => [
                    'csv' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                        ],
                    ],
                    'unchanged' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                        ],
                    ],
                ],
                'types' => [
                    'content' => [
                        'showitem' => 'csv, unchanged',
                        'columnsOverrides' => [
                            'csv' => ['config' => ['allowed' => 'tt_content']],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new GroupField('foo', 'unchanged', ['pages']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextGroupFieldWithoutMm(), false));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesWithoutTypeField(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        $groupField = new GroupField('foo', 'csv', ['pages']);
        self::assertSame(['pages'], (new TcaHelper())->getAllowedTablesOfRecordType($groupField, 'content'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesOfType(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        self::assertSame(['tt_content'], (new TcaHelper())->getAllowedTablesOfRecordType(self::getRecordTypeGroupField(), 'content'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsNullForTypeWithIgnoredRelations(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        self::assertNull((new TcaHelper())->getAllowedTablesOfRecordType(self::getRecordTypeGroupField(), 'typeChanged'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesForTypeWithoutChange(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        self::assertSame(['pages'], (new TcaHelper())->getAllowedTablesOfRecordType(self::getRecordTypeGroupField(), '0'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesForUnknownTypeIfFallbackTypeHasNoChange(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        self::assertSame(['pages'], (new TcaHelper())->getAllowedTablesOfRecordType(self::getRecordTypeGroupField(), 'removed'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesForNullType(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        self::assertSame(['pages'], (new TcaHelper())->getAllowedTablesOfRecordType(self::getRecordTypeGroupField(), null));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsNullForUnknownTypeIfFallbackTypeZeroHasChange(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        $groupField = new GroupField('foo', 'csv', ['pages'], 'record_type', ['0' => ['tt_content']]);
        self::assertNull((new TcaHelper())->getAllowedTablesOfRecordType($groupField, 'removed'));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsNullForUnknownTypeIfFallbackTypeOneHasChange(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        unset($GLOBALS['TCA']['foo']['types']['0']);
        $groupField = new GroupField('foo', 'csv', ['pages'], 'record_type', ['1' => ['tt_content']]);
        self::assertNull((new TcaHelper())->getAllowedTablesOfRecordType($groupField, 'removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function getRecordTypeTca(): array
    {
        return [
            'foo' => [
                'ctrl' => [
                    'type' => 'record_type',
                ],
                'types' => [
                    '0' => ['showitem' => 'csv'],
                    '1' => ['showitem' => 'csv'],
                    'content' => ['showitem' => 'csv'],
                    'typeChanged' => ['showitem' => 'csv'],
                ],
            ],
        ];
    }

    private static function getRecordTypeGroupField(): GroupField
    {
        return new GroupField('foo', 'csv', ['pages'], 'record_type', [
            'content' => ['tt_content'],
            'typeChanged' => null,
        ]);
    }

    #[Test]
    public function getNextGroupFieldWithMmThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextGroupFieldWithMm() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
    public function getNextGroupFieldWithMmReturnsGroupFields(): void
    {
        $GLOBALS['TCA'] = self::getGroupFieldTca();
        $result = [];
        foreach ((new TcaHelper())->getNextGroupFieldWithMm() as $item) {
            $result[] = $item;
        }
        $expected = [
            new MmField('foo', 'mm', 'foo_mm', ['*'], []),
            new MmField('foo', 'mmMatchFields', 'foo_mm', ['tt_content'], ['fieldname' => 'mmMatchFields']),
        ];
        self::assertEquals($expected, $result);
    }

    #[Test]
    public function getNextGroupFieldWithMmReturnsAllowedTablesByType(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'type' => 'record_type',
                ],
                'columns' => [
                    'mm' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'mm',
                            ],
                        ],
                    ],
                ],
                'types' => [
                    'content' => [
                        'showitem' => 'mm',
                        'columnsOverrides' => [
                            'mm' => ['config' => ['allowed' => 'tt_content']],
                        ],
                    ],
                    'typeChanged' => [
                        'showitem' => 'mm',
                        'columnsOverrides' => [
                            'mm' => ['config' => ['type' => 'input']],
                        ],
                    ],
                ],
            ],
        ];
        $result = iterator_to_array((new TcaHelper())->getNextGroupFieldWithMm(), false);
        self::assertCount(1, $result);
        self::assertSame('mm', $result[0]->fieldName);
        self::assertSame(['pages'], $result[0]->allowedTables);
        self::assertSame('record_type', $result[0]->typeField);
        self::assertSame(['content' => ['tt_content'], 'typeChanged' => null], $result[0]->allowedTablesByType);
    }

    #[Test]
    public function getNextGroupFieldWithMmSkipsFieldIfTypeChangesMmSettings(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'type' => 'record_type',
                ],
                'columns' => [
                    'matchFieldsChanged' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'matchFieldsChanged',
                            ],
                        ],
                    ],
                    'mmChanged' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'mmChanged',
                            ],
                        ],
                    ],
                    'unchanged' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'unchanged',
                            ],
                        ],
                    ],
                ],
                'types' => [
                    'content' => [
                        'showitem' => 'matchFieldsChanged, mmChanged, unchanged',
                        'columnsOverrides' => [
                            'matchFieldsChanged' => ['config' => ['MM_match_fields' => ['fieldname' => 'other']]],
                            'mmChanged' => ['config' => ['MM' => 'bar_mm']],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new MmField('foo', 'unchanged', 'foo_mm', ['pages'], ['fieldname' => 'unchanged']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextGroupFieldWithMm(), false));
    }

    #[Test]
    public function getAllowedTablesOfRecordTypeReturnsAllowedTablesOfTypeForMmField(): void
    {
        $GLOBALS['TCA'] = self::getRecordTypeTca();
        $groupField = new MmField('foo', 'mm', 'foo_mm', ['pages'], [], 'record_type', ['content' => ['tt_content']]);
        self::assertSame(['tt_content'], (new TcaHelper())->getAllowedTablesOfRecordType($groupField, 'content'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function getGroupFieldTca(): array
    {
        return [
            'foo' => [
                'ctrl' => [
                    'transOrigPointerField' => 'l10n_parent',
                    'translationSource' => 'l10n_source',
                ],
                'columns' => [
                    'noConfig' => [],
                    'notGroup' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'pages',
                        ],
                    ],
                    'noAllowed' => [
                        'config' => [
                            'type' => 'group',
                        ],
                    ],
                    'l10n_parent' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'foo',
                        ],
                    ],
                    'l10n_source' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'foo',
                        ],
                    ],
                    'csv' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages, tt_content',
                        ],
                    ],
                    'mm' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => '*',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'mmMatchFields' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'tt_content',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'mmMatchFields',
                            ],
                        ],
                    ],
                    'mmOppositeField' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'bar',
                            'MM' => 'bar_mm',
                            'MM_opposite_field' => 'foos',
                        ],
                    ],
                    'mmTableWhere' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'bar',
                            'MM' => 'bar_mm',
                            'MM_table_where' => 'AND {#bar_mm}.{#uid_local} = ###THIS_UID###',
                        ],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function getNextSelectFieldWithoutMmThrowsExceptionIfTcaIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1688203176);
        $GLOBALS['TCA'] = null;
        $subject = new TcaHelper();
        foreach ($subject->getNextSelectFieldWithoutMm() as $item) {
            // Trigger iterable
        }
    }

    #[Test]
    public function getNextSelectFieldWithoutMmReturnsSelectAndCategoryFields(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'transOrigPointerField' => 'l10n_parent',
                    'translationSource' => 'l10n_source',
                ],
                'columns' => [
                    'noConfig' => [],
                    'notSelect' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                        ],
                    ],
                    'noForeignTable' => [
                        'config' => [
                            'type' => 'select',
                            'items' => [
                                ['label' => 'One', 'value' => 1],
                            ],
                        ],
                    ],
                    'l10n_parent' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'foo',
                        ],
                    ],
                    'l10n_source' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'foo',
                        ],
                    ],
                    'select' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'fe_groups',
                            'items' => [
                                ['label' => 'Hide at login', 'value' => -1],
                                ['label' => 'Groups', 'value' => '--div--'],
                                ['label' => 'Static', 'value' => 5],
                            ],
                        ],
                    ],
                    'selectMm' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'pages',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'category' => [
                        'config' => [
                            'type' => 'category',
                            'relationship' => 'oneToMany',
                            'foreign_table' => 'sys_category',
                        ],
                    ],
                    'categoryMm' => [
                        'config' => [
                            'type' => 'category',
                            'relationship' => 'manyToMany',
                            'foreign_table' => 'sys_category',
                            'MM' => 'sys_category_record_mm',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new ForeignTableListField('foo', 'select', ['fe_groups'], ['-1', '--div--', '5']),
            new ForeignTableListField('foo', 'category', ['sys_category']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextSelectFieldWithoutMm(), false));
    }

    #[Test]
    public function getNextSelectFieldWithoutMmSkipsFieldsPointingToInlineParent(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table' => [
                'columns' => [
                    'children' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'child_table',
                            'foreign_field' => 'parent_uid',
                        ],
                    ],
                ],
            ],
            'child_table' => [
                'columns' => [
                    'parent_uid' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'parent_table',
                        ],
                    ],
                    'related' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'tt_content',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new ForeignTableListField('child_table', 'related', ['tt_content']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextSelectFieldWithoutMm(), false));
    }

    #[Test]
    public function getNextSelectFieldWithoutMmReturnsForeignTableByTypeAndStaticItemsOfAllTypes(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'ctrl' => [
                    'type' => 'record_type',
                ],
                'columns' => [
                    'record_type' => [
                        'config' => [
                            'type' => 'select',
                        ],
                    ],
                    'select' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'pages',
                            'items' => [
                                ['label' => 'Static', 'value' => -1],
                            ],
                        ],
                    ],
                ],
                'types' => [
                    'noOverride' => [
                        'showitem' => 'record_type, select',
                    ],
                    'content' => [
                        'showitem' => 'record_type, select',
                        'columnsOverrides' => [
                            'select' => ['config' => ['foreign_table' => 'tt_content']],
                        ],
                    ],
                    'notShown' => [
                        'showitem' => 'record_type',
                        'columnsOverrides' => [
                            'select' => ['config' => ['foreign_table' => 'tt_content', 'items' => [['label' => 'Not shown', 'value' => 7]]]],
                        ],
                    ],
                    'otherItems' => [
                        'showitem' => 'record_type, select',
                        'columnsOverrides' => [
                            'select' => ['config' => ['items' => [['label' => 'Other', 'value' => 3]]]],
                        ],
                    ],
                    'typeChanged' => [
                        'showitem' => 'record_type, select',
                        'columnsOverrides' => [
                            'select' => ['config' => ['type' => 'input']],
                        ],
                    ],
                    'mmAdded' => [
                        'showitem' => 'record_type, select',
                        'columnsOverrides' => [
                            'select' => ['config' => ['MM' => 'foo_mm']],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new ForeignTableListField(
                'foo',
                'select',
                ['pages'],
                ['-1', '7', '3'],
                'record_type',
                [
                    'content' => ['tt_content'],
                    'typeChanged' => null,
                    'mmAdded' => null,
                ],
            ),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextSelectFieldWithoutMm(), false));
    }

    #[Test]
    public function getNextSelectFieldWithMmReturnsSelectFieldsWithMm(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'columns' => [
                    'group' => [
                        'config' => [
                            'type' => 'group',
                            'allowed' => 'pages',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'selectWithoutMm' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'pages',
                        ],
                    ],
                    'selectWithoutForeignTable' => [
                        'config' => [
                            'type' => 'select',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'selectOppositeField' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'bar',
                            'MM' => 'bar_mm',
                            'MM_opposite_field' => 'foos',
                        ],
                    ],
                    'category' => [
                        'config' => [
                            'type' => 'category',
                            'foreign_table' => 'sys_category',
                            'MM' => 'sys_category_record_mm',
                        ],
                    ],
                    'select' => [
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'pages',
                            'MM' => 'foo_mm',
                            'MM_match_fields' => [
                                'fieldname' => 'select',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new MmField('foo', 'select', 'foo_mm', ['pages'], ['fieldname' => 'select']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextSelectFieldWithMm(), false));
    }

    #[Test]
    public function getNextInlineFieldWithMmReturnsInlineFieldsWithMm(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'columns' => [
                    'inlineWithoutMm' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                        ],
                    ],
                    'inlineWithForeignField' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                            'foreign_field' => 'parent',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'inlineTableWhere' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                            'MM' => 'foo_mm',
                            'MM_table_where' => 'AND {#foo_mm}.{#uid_local} = ###THIS_UID###',
                        ],
                    ],
                    'inline' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                            'MM' => 'foo_mm',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new MmField('foo', 'inline', 'foo_mm', ['bar'], []),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineFieldWithMm(), false));
    }

    #[Test]
    public function getNextInlineFieldWithUidListReturnsInlineFieldsWithoutForeignFieldAndMm(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => [
                'columns' => [
                    'inlineWithForeignField' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                            'foreign_field' => 'parent',
                        ],
                    ],
                    'inlineWithMm' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                            'MM' => 'foo_mm',
                        ],
                    ],
                    'file' => [
                        'config' => [
                            'type' => 'file',
                            'foreign_table' => 'sys_file_reference',
                        ],
                    ],
                    'inline' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'bar',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            new ForeignTableListField('foo', 'inline', ['bar']),
        ];
        self::assertEquals($expected, iterator_to_array((new TcaHelper())->getNextInlineFieldWithUidList(), false));
    }

    #[Test]
    public function hasWorkspaceAwareTargetTableReturnsTrueForWorkspaceAwareAllowedTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => ['ctrl' => []],
            'bar' => ['ctrl' => ['versioningWS' => true]],
        ];
        self::assertTrue((new TcaHelper())->hasWorkspaceAwareTargetTable(new GroupField('baz', 'csv', ['foo', 'bar'])));
    }

    #[Test]
    public function hasWorkspaceAwareTargetTableReturnsFalseWithoutWorkspaceAwareAllowedTable(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => ['ctrl' => []],
        ];
        self::assertFalse((new TcaHelper())->hasWorkspaceAwareTargetTable(new ForeignTableListField('baz', 'select', ['foo'])));
    }

    #[Test]
    public function hasWorkspaceAwareTargetTableReturnsTrueForAnyTable(): void
    {
        $GLOBALS['TCA'] = [];
        self::assertTrue((new TcaHelper())->hasWorkspaceAwareTargetTable(new MmField('baz', 'mm', 'baz_mm', ['*'], [])));
    }

    #[Test]
    public function hasWorkspaceAwareTargetTableReturnsTrueForWorkspaceAwareTableOfRecordType(): void
    {
        $GLOBALS['TCA'] = [
            'foo' => ['ctrl' => []],
            'bar' => ['ctrl' => ['versioningWS' => true]],
        ];
        $field = new GroupField('baz', 'csv', ['foo'], 'record_type', ['content' => ['bar'], 'ignored' => null]);
        self::assertTrue((new TcaHelper())->hasWorkspaceAwareTargetTable($field));
    }

    #[Test]
    public function getFieldNameByCtrlNameReturnsName(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['bar'] = 'baz';
        self::assertSame('baz', (new TcaHelper())->getFieldNameByCtrlName('foo', 'bar'));
    }

    #[Test]
    public function getFieldNameByCtrlNameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1646162580);
        (new TcaHelper())->getFieldNameByCtrlName('foo', 'bar');
    }

    #[Test]
    public function getDeletedFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['delete'] = 'deletedField';
        self::assertSame('deletedField', (new TcaHelper())->getDeletedField('foo'));
    }

    #[Test]
    public function getDeletedFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getDeletedField('foo'));
    }

    #[Test]
    public function getHiddenFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns']['disabled'] = 'hiddenField';
        self::assertSame('hiddenField', (new TcaHelper())->getHiddenField('foo'));
    }

    #[Test]
    public function getHiddenFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getHiddenField('foo'));
    }

    #[Test]
    public function getVisibilityFieldsReturnsFields(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'disabled' => 'hiddenField',
            'starttime' => 'startTimeField',
            'endtime' => 'endTimeField',
            'fe_group' => 'feGroupField',
        ];
        self::assertSame(['hiddenField', 'startTimeField', 'endTimeField'], (new TcaHelper())->getVisibilityFields('foo'));
    }

    #[Test]
    public function getVisibilityFieldsReturnsEmptyArray(): void
    {
        self::assertSame([], (new TcaHelper())->getVisibilityFields('foo'));
    }

    #[Test]
    public function isVisibleInFrontendReturnsTrueWithoutEnableColumns(): void
    {
        self::assertTrue((new TcaHelper())->isVisibleInFrontend('foo', ['uid' => 1], 1000));
    }

    #[Test]
    public function isVisibleInFrontendReturnsTrueIfVisible(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ];
        self::assertTrue((new TcaHelper())->isVisibleInFrontend('foo', ['hidden' => 0, 'starttime' => 1000, 'endtime' => 1001], 1000));
    }

    #[Test]
    public function isVisibleInFrontendReturnsTrueWithoutEndTime(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'endtime' => 'endtime',
        ];
        self::assertTrue((new TcaHelper())->isVisibleInFrontend('foo', ['endtime' => 0], 1000));
    }

    #[Test]
    public function isVisibleInFrontendReturnsFalseIfHidden(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'disabled' => 'hidden',
        ];
        self::assertFalse((new TcaHelper())->isVisibleInFrontend('foo', ['hidden' => 1], 1000));
    }

    #[Test]
    public function isVisibleInFrontendReturnsFalseIfStartTimeIsInFuture(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'starttime' => 'starttime',
        ];
        self::assertFalse((new TcaHelper())->isVisibleInFrontend('foo', ['starttime' => 1001], 1000));
    }

    #[Test]
    public function isVisibleInFrontendReturnsFalseIfEndTimeIsOver(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['enablecolumns'] = [
            'endtime' => 'endtime',
        ];
        self::assertFalse((new TcaHelper())->isVisibleInFrontend('foo', ['endtime' => 1000], 1000));
    }

    #[Test]
    public function getCreateUserIdFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['cruser_id'] = 'cruserIdField';
        self::assertSame('cruserIdField', (new TcaHelper())->getCreateUserIdField('foo'));
    }

    #[Test]
    public function getCreateUserIdFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getCreateUserIdField('foo'));
    }

    #[Test]
    public function getTimestampFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['tstamp'] = 'tstampField';
        self::assertSame('tstampField', (new TcaHelper())->getTimestampField('foo'));
    }

    #[Test]
    public function getTimestampFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTimestampField('foo'));
    }

    #[Test]
    public function getLanguageFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['languageField'] = 'langField';
        self::assertSame('langField', (new TcaHelper())->getLanguageField('foo'));
    }

    #[Test]
    public function getLanguageFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getLanguageField('foo'));
    }

    #[Test]
    public function getTranslationParentFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['transOrigPointerField'] = 'l10n_parent';
        self::assertSame('l10n_parent', (new TcaHelper())->getTranslationParentField('foo'));
    }

    #[Test]
    public function getTranslationParentFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTranslationParentField('foo'));
    }

    #[Test]
    public function getTranslationSourceFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['translationSource'] = 'l10n_parent';
        self::assertSame('l10n_parent', (new TcaHelper())->getTranslationSourceField('foo'));
    }

    #[Test]
    public function getTranslationSourceFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTranslationSourceField('foo'));
    }

    #[Test]
    public function getWorkspaceIdFieldReturnsFieldWithTrue(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = true;
        self::assertSame('t3ver_wsid', (new TcaHelper())->getWorkspaceIdField('foo'));
    }

    #[Test]
    public function getWorkspaceIdFieldReturnsFieldWithOne(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = 1;
        self::assertSame('t3ver_wsid', (new TcaHelper())->getWorkspaceIdField('foo'));
    }

    #[Test]
    public function getWorkspaceFieldReturnsNullWithNull(): void
    {
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    #[Test]
    public function getWorkspaceFieldReturnsNullWithFalse(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = false;
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    #[Test]
    public function getWorkspaceFieldReturnsNullWithZero(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['versioningWS'] = 0;
        self::assertNull((new TcaHelper())->getWorkspaceIdField('foo'));
    }

    #[Test]
    public function getTypeFieldReturnsField(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['type'] = 'typeField';
        self::assertSame('typeField', (new TcaHelper())->getTypeField('foo'));
    }

    #[Test]
    public function getTypeFieldReturnsNullForColonTypes(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['type'] = 'foo:bar';
        self::assertNull((new TcaHelper())->getTypeField('foo'));
    }

    #[Test]
    public function getTypeFieldReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getTypeField('foo'));
    }
    #[Test]
    public function getLabelFieldsReturnsNull(): void
    {
        self::assertNull((new TcaHelper())->getLabelFields('foo'));
    }

    #[Test]
    public function getLabelFieldsReturnsLabel(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label'] = 'labelField';
        self::assertSame(['labelField'], (new TcaHelper())->getLabelFields('foo'));
    }

    #[Test]
    public function getLabelFieldsReturnsLabelAlt(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label_alt'] = 'field1, field2, , field3 ';
        self::assertSame(['field1', 'field2', 'field3'], (new TcaHelper())->getLabelFields('foo'));
    }

    #[Test]
    public function getLabelFieldsReturnsCombinedLabelAndLabelAlt(): void
    {
        $GLOBALS['TCA']['foo']['ctrl']['label'] = 'labelField';
        $GLOBALS['TCA']['foo']['ctrl']['label_alt'] = 'labelAlt1, labelAlt2';
        self::assertSame(['labelField', 'labelAlt1', 'labelAlt2'], (new TcaHelper())->getLabelFields('foo'));
    }

    #[Test]
    public function getInlineParentsOfTableReturnsAllParentsOfChildTable(): void
    {
        $GLOBALS['TCA'] = [
            'parent_table_1' => [
                'columns' => [
                    'children' => self::getInlineColumn(['parent_table' => 'parent_table_1']),
                    'not_inline' => [
                        'config' => [
                            'type' => 'input',
                        ],
                    ],
                ],
            ],
            'parent_table_2' => [
                'columns' => [
                    'files' => [
                        'config' => [
                            'type' => 'file',
                            'foreign_table' => 'child_table',
                            'foreign_field' => 'parent_uid',
                            'foreign_table_field' => 'parent_table',
                        ],
                    ],
                    'other_children' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'other_child_table',
                            'foreign_field' => 'parent_uid',
                        ],
                    ],
                ],
            ],
            'child_table' => self::getInlineChildTca(),
        ];
        $expected = [
            [
                'tableName' => 'parent_table_1',
                'fieldName' => 'children',
                'foreignField' => 'parent_uid',
                'foreignTableField' => null,
                'foreignMatchFields' => ['parent_table' => 'parent_table_1'],
            ],
            [
                'tableName' => 'parent_table_2',
                'fieldName' => 'files',
                'foreignField' => 'parent_uid',
                'foreignTableField' => 'parent_table',
                'foreignMatchFields' => [],
            ],
        ];
        self::assertSame($expected, (new TcaHelper())->getInlineParentsOfTable('child_table'));
    }
}
