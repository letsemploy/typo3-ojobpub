<?php

// Only used for installations without Composer (TER). composer.json is authoritative.
$EM_CONF[$_EXTKEY] = [
    'title' => 'oJobPub',
    'description' => 'Publishes job openings as an oJobPub 1.0 feed at /.well-known/ojobpub.json.',
    'category' => 'fe',
    'state' => 'alpha',
    'version' => '0.1.0',
    'author' => "Let's Employ",
    'author_company' => 'letsemploy.org',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
