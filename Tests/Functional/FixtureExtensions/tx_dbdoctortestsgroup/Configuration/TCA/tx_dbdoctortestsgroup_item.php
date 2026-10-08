<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsgroup_item',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'iconfile' => 'EXT:tx_dbdoctortestsgroup/Resources/Public/Icons/Extension.svg',
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
        'relations_csv' => [
            'label' => 'Relations to pages and tt_content, comma separated list',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages,tt_content',
            ],
        ],
        'relations_mm' => [
            'label' => 'Relations to pages and tt_content, MM',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages,tt_content',
                'MM' => 'tx_dbdoctortestsgroup_item_mm',
                'MM_match_fields' => [
                    'fieldname' => 'relations_mm',
                ],
            ],
        ],
        'relations_mm_single' => [
            'label' => 'Relations to tt_content, MM',
            'config' => [
                'type' => 'group',
                'allowed' => 'tt_content',
                'MM' => 'tx_dbdoctortestsgroup_item_mm',
                'MM_match_fields' => [
                    'fieldname' => 'relations_mm_single',
                ],
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, relations_csv, relations_mm, relations_mm_single'],
    ],
];
