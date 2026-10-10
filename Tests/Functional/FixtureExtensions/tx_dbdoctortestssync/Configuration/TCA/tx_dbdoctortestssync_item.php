<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestssync_item, fields with allowLanguageSynchronization',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'iconfile' => 'EXT:tx_dbdoctortestssync/Resources/Public/Icons/Extension.svg',
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
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'settings' => [
            'label' => 'Settings, json',
            'config' => [
                'type' => 'json',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'relation_select' => [
            'label' => 'Relation to pages, select',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'pages',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'relation_group' => [
            'label' => 'Relation to pages, group',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'relation_category' => [
            'label' => 'Relation to categories',
            'config' => [
                'type' => 'category',
                'relationship' => 'oneToMany',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'static_select' => [
            'label' => 'Static items, select',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Empty', 'value' => ''],
                    ['label' => 'Zero', 'value' => '0'],
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, settings, relation_select, relation_group, relation_category, static_select'],
    ],
];
