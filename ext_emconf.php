<?php

$EM_CONF['dbdoctor'] = [
    'title' => 'TYPO3 Database doctor',
    'description' => 'Find and fix TYPO3 database inconsistencies',
    'category' => 'misc',
    'version' => '0.2.0',
    'module' => '',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'author' => 'Christian Kuhn',
    'author_email' => 'lolli@schwarzbu.ch',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
