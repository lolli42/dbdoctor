<?php

declare(strict_types=1);

$EM_CONF['tx_dbdoctortestsgroup'] = [
    'title' => 'Dbdoctor Testing tx_dbdoctortestsgroup',
    'description' => 'Dbdoctor Testing tx_dbdoctortestsgroup',
    'category' => 'example',
    'version' => '0.0.1',
    'state' => 'beta',
    'author' => 'Christian Kuhn',
    'author_email' => 'lolli@schwarzbu.ch',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '12.99.99-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
