<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsinline_parent',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'iconfile' => 'EXT:tx_dbdoctortestsinline/Resources/Public/Icons/Extension.svg',
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
        'children_list' => [
            'label' => 'Children, comma separated uid list',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsinline_child',
            ],
        ],
        'children_mm' => [
            'label' => 'Children, MM',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsinline_child',
                'MM' => 'tx_dbdoctortestsinline_parent_mm',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, children_list, children_mm',
        ],
    ],
];
