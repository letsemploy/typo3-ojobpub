<?php

declare(strict_types=1);

defined('TYPO3') or die();

// TYPO3 v13 needs ctrl.searchFields for the list module search. v14 removed the
// option (Breaking #106972) and searches all suitable fields by default.
if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 14) {
    $GLOBALS['TCA']['tx_ojobpub_domain_model_job']['ctrl']['searchFields'] = 'title,description,category,reference_id,tags';
}
