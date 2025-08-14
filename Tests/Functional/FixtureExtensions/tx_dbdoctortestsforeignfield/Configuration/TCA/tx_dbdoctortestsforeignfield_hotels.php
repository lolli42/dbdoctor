<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Dbdoctor tests tx_dbdoctortestsforeignfield_hotels',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l18n_parent',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:tx_dbdoctortestsforeignfield/Resources/Public/Icons/Extension.svg',
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type' => 'language',
            ],
        ],
        'l18n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'foreign_table' => 'tx_dbdoctortestsforeignfield_hotels',
                'foreign_table_where' => 'AND {#tx_dbdoctortestsforeignfield_hotels}.{#pid}=###CURRENT_PID### AND {#tx_dbdoctortestsforeignfield_hotels}.{#sys_language_uid} IN (-1,0)',
                'default' => 0,
            ],
        ],
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'parentid' => [
            'config' => [
                'type' => 'passthrough',
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'Hotel',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'required' => true,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => '--div--;General, title,--div--;Visibility, sys_language_uid, l18n_parent, hidden'],
    ],
    'palettes' => [
        '1' => ['showitem' => ''],
    ],
];
