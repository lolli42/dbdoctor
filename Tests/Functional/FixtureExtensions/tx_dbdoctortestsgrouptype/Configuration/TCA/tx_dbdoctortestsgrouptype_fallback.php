<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsgrouptype_fallback',
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
    ],
    'types' => [
        // Type "0" is the fallback for type values without "types" entry in FormEngine and
        // core v13 DataHandler, while core v14 DataHandler uses the base config for them.
        '0' => [
            'showitem' => 'title, record_type, relations_csv',
            'columnsOverrides' => [
                'relations_csv' => [
                    'config' => [
                        'allowed' => 'tt_content',
                    ],
                ],
            ],
        ],
    ],
];
