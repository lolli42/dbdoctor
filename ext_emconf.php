<?php

$EM_CONF['dbdoctor'] = [
    'title' => 'TYPO3 Database doctor',
    'description' => 'Find and fix TYPO3 database inconsistencies',
    'category' => 'misc',
    'version' => '1.0.2',
    'module' => '',
    'state' => 'stable',
    'author' => 'Christian Kuhn',
    'author_email' => 'lolli@schwarzbu.ch',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
