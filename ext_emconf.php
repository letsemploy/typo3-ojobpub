<?php

// Only used by TYPO3 13 without Composer (TER). TYPO3 14 reads extra.typo3/cms.version
// from composer.json instead; keep both versions in sync.
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
