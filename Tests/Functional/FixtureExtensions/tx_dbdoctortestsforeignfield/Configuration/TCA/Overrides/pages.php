<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'pages',
    [
        // Child table is shared with tt_content, no foreign_table_field
        'tx_dbdoctortestsforeignfield_items' => [
            'label' => 'Items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_dbdoctortestsforeignfield_items',
                'foreign_field' => 'parentid',
                'foreign_match_fields' => [
                    'parenttable' => 'pages',
                    'parentfield' => 'tx_dbdoctortestsforeignfield_items',
                ],
            ],
        ],
    ]
);
