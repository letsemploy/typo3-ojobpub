<?php

declare(strict_types=1);

/*
 * Needs the site resolver; must run before everything that could redirect or
 * 404 the well-known path: EXT:redirects, base redirects, static routes and
 * the page resolver. Because EXT:redirects runs after frontend authentication,
 * the resolved position is after authentication, right before the redirect
 * handler. Resolved without cycles on TYPO3 v13.4 and v14.3, with and without
 * EXT:redirects.
 */
return [
    'frontend' => [
        'letsemploy/ojobpub/well-known-feed' => [
            'target' => \LetsEmploy\OJobPub\Middleware\WellKnownFeed::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-redirects/redirecthandler',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
