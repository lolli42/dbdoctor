<?php

$EM_CONF['dbhealth'] = [
    'title' => 'TYPO3 Database health',
    'description' => 'Find and fix TYPO3 database inconsistencies',
    'category' => 'misc',
    'version' => '0.0.0',
    'module' => '',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'author' => 'Christian Kuhn',
    'author_email' => 'lolli@schwarzbu.ch',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
