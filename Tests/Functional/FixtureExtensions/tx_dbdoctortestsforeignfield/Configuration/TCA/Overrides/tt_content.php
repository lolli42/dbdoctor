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
    ]
);

ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;Dbdoctor tests, tx_dbdoctortestsforeignfield_hotels'
);
