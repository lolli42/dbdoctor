<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsselect_item',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'type' => 'record_type',
        'iconfile' => 'EXT:tx_dbdoctortestsselect/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
        'record_type' => [
            'label' => 'Record type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Default', 'value' => '0'],
                    ['label' => 'Content', 'value' => 'content'],
                    ['label' => 'Not shown', 'value' => 'not_shown'],
                    ['label' => 'Type changed', 'value' => 'type_changed'],
                ],
            ],
        ],
        'relations' => [
            'label' => 'Relations to pages, comma separated list, with static items',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'pages',
                'items' => [
                    ['label' => 'Static negative', 'value' => -1],
                    ['label' => 'Static positive', 'value' => 5],
                ],
            ],
        ],
        'relations_mm' => [
            'label' => 'Relations to pages, MM',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'pages',
                'MM' => 'tx_dbdoctortestsselect_item_mm',
            ],
        ],
        'relation_single' => [
            'label' => 'Relation to pages, single uid',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'pages',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'category_single' => [
            'label' => 'Category, oneToOne',
            'config' => [
                'type' => 'category',
                'relationship' => 'oneToOne',
            ],
        ],
        'category_multi' => [
            'label' => 'Categories, oneToMany',
            'config' => [
                'type' => 'category',
                'relationship' => 'oneToMany',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, record_type, relations, relations_mm, relation_single, category_single, category_multi',
        ],
        // Relations to tt_content
        'content' => [
            'showitem' => 'title, record_type, relations, relations_mm',
            'columnsOverrides' => [
                'relations' => [
                    'config' => [
                        'foreign_table' => 'tt_content',
                    ],
                ],
                'relations_mm' => [
                    'config' => [
                        'foreign_table' => 'tt_content',
                    ],
                ],
            ],
        ],
        // Field not shown: The core ignores columnsOverrides, relations to pages
        'not_shown' => [
            'showitem' => 'title, record_type',
            'columnsOverrides' => [
                'relations' => [
                    'config' => [
                        'foreign_table' => 'tt_content',
                    ],
                ],
            ],
        ],
        // Not a select field in this type: Not touched
        'type_changed' => [
            'showitem' => 'title, record_type, relations',
            'columnsOverrides' => [
                'relations' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
            ],
        ],
    ],
];
