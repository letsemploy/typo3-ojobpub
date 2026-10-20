<?php

declare(strict_types=1);

$ll = 'LLL:EXT:ojobpub/Resources/Private/Language/locallang_db.xlf:';
$table = 'tx_ojobpub_domain_model_location';

return [
    'ctrl' => [
        'title' => $ll . $table,
        'label' => 'city',
        'label_alt' => 'country',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'hideTable' => true,
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'iconfile' => 'EXT:ojobpub/Resources/Public/Icons/location.svg',
    ],
    'types' => [
        '0' => ['showitem' => 'city, country'],
    ],
    'columns' => [
        'job' => [
            'config' => ['type' => 'passthrough'],
        ],
        'city' => [
            'label' => $ll . $table . '.city',
            'config' => ['type' => 'input', 'size' => 30, 'max' => 255, 'eval' => 'trim'],
        ],
        'country' => [
            'label' => $ll . $table . '.country',
            'description' => $ll . $table . '.country.description',
            // TODO: selectSingle with ISO 3166-1 codes (e.g. from ICU, see Normalizer::country()).
            'config' => ['type' => 'input', 'size' => 4, 'max' => 2, 'eval' => 'trim,upper', 'placeholder' => 'CH'],
        ],
    ],
];
