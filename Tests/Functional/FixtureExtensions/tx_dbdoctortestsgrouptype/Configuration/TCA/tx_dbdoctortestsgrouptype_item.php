<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsgrouptype_item',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'type' => 'record_type',
        'iconfile' => 'EXT:tx_dbdoctortestsgrouptype/Resources/Public/Icons/Extension.svg',
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
                    ['label' => 'Content and pages', 'value' => 'content_and_pages'],
                    ['label' => 'Not shown', 'value' => 'not_shown'],
                    ['label' => 'Type changed', 'value' => 'type_changed'],
                ],
            ],
        ],
        'relations_csv' => [
            'label' => 'Relations to pages, comma separated list',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
            ],
        ],
        'relations_mm' => [
            'label' => 'Relations to pages, MM',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'MM' => 'tx_dbdoctortestsgrouptype_item_mm',
                'MM_match_fields' => [
                    'fieldname' => 'relations_mm',
                ],
            ],
        ],
        'relations_mm_changed' => [
            'label' => 'Relations to pages, MM, type "content" changes MM settings',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'MM' => 'tx_dbdoctortestsgrouptype_item_mm',
                'MM_match_fields' => [
                    'fieldname' => 'relations_mm_changed',
                ],
            ],
        ],
    ],
    'palettes' => [
        'relations' => [
            'showitem' => 'relations_csv, relations_mm',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, record_type, relations_csv, relations_mm, relations_mm_changed',
        ],
        // Field in a palette, relations to tt_content
        'content' => [
            'showitem' => 'title, record_type, --palette--;;relations, relations_mm_changed',
            'columnsOverrides' => [
                'relations_csv' => [
                    'config' => [
                        'allowed' => 'tt_content',
                    ],
                ],
                'relations_mm' => [
                    'config' => [
                        'allowed' => 'tt_content',
                    ],
                ],
                // MM rows can not be assigned to a type: The whole field is not touched
                'relations_mm_changed' => [
                    'config' => [
                        'MM_match_fields' => [
                            'fieldname' => 'relations_mm_content',
                        ],
                    ],
                ],
            ],
        ],
        // Field with label, relations to tt_content and pages
        'content_and_pages' => [
            'showitem' => 'title, record_type, relations_csv;Relations',
            'columnsOverrides' => [
                'relations_csv' => [
                    'config' => [
                        'allowed' => 'tt_content, pages',
                    ],
                ],
            ],
        ],
        // Field not shown: The core ignores columnsOverrides, relations to pages
        'not_shown' => [
            'showitem' => 'title, record_type',
            'columnsOverrides' => [
                'relations_csv' => [
                    'config' => [
                        'allowed' => 'tt_content',
                    ],
                ],
            ],
        ],
        // Not a group field in this type: Not touched
        'type_changed' => [
            'showitem' => 'title, record_type, relations_csv, relations_mm',
            'columnsOverrides' => [
                'relations_csv' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
                'relations_mm' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
            ],
        ],
    ],
];
