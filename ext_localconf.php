<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Feed cache. In group "pages" so that "Flush frontend caches" also flushes the feeds.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ojobpub'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ojobpub']['groups'] ??= ['pages'];

// Invalidate feeds when job records change.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['ojobpub']
    = \LetsEmploy\OJobPub\Hooks\DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['ojobpub']
    = \LetsEmploy\OJobPub\Hooks\DataHandlerHook::class;
