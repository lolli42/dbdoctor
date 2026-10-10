<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_dbdoctortestsforeignfield_hotels' => [
            'label' => 'Hotels',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsforeignfield_hotels',
                'foreign_field' => 'parentid',
            ],
        ],
        // Child table is shared with pages, no foreign_table_field
        'tx_dbdoctortestsforeignfield_items' => [
            'label' => 'Items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsforeignfield_items',
                'foreign_field' => 'parentid',
                'foreign_match_fields' => [
                    'parenttable' => 'tt_content',
                    'parentfield' => 'tx_dbdoctortestsforeignfield_items',
                ],
            ],
        ],
        // Child table is shared with pages, no foreign_table_field
        'tx_dbdoctortestsforeignfield_items2' => [
            'label' => 'Items 2',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsforeignfield_items',
                'foreign_field' => 'parentid',
                'foreign_match_fields' => [
                    'parenttable' => 'tt_content',
                    'parentfield' => 'tx_dbdoctortestsforeignfield_items2',
                ],
            ],
        ],
    ]
);

ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;Dbdoctor tests, tx_dbdoctortestsforeignfield_hotels'
);
