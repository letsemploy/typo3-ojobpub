<?php

declare(strict_types=1);

/*
 * Field names map 1:1 to oJobPub (snake_case here, camelCase in the feed).
 * System columns (hidden, starttime, endtime, sys_language_uid, l10n_parent,
 * l10n_diffsource) are added automatically by TYPO3 >= 13.3 from "ctrl".
 * Database columns are derived from TCA; ext_tables.sql only declares the table.
 */

$ll = 'LLL:EXT:ojobpub/Resources/Private/Language/locallang_db.xlf:';
$table = 'tx_ojobpub_domain_model_job';

$items = static function (string $field, array $values) use ($ll, $table): array {
    $items = [['label' => '', 'value' => '']];
    foreach ($values as $value) {
        $items[] = ['label' => $ll . $table . '.' . $field . '.' . $value, 'value' => $value];
    }
    return $items;
};

// Not translated: taken from the default-language record (see NativeSource::TRANSLATED_FIELDS).
$excluded = ['l10n_mode' => 'exclude', 'l10n_display' => 'defaultAsReadonly'];

return [
    'ctrl' => [
        'title' => $ll . $table,
        'label' => 'title',
        'label_alt' => 'reference_id',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'iconfile' => 'EXT:ojobpub/Resources/Public/Icons/job.svg',
        // searchFields: set for v13 only in Overrides/ (removed in v14, where all text fields are searchable).
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;' . $ll . 'tabs.job,
                    title, --palette--;;classification, url, description, tags,
                --div--;' . $ll . 'tabs.conditions,
                    --palette--;;dates, --palette--;;workload, --palette--;;salary,
                --div--;' . $ll . 'tabs.locations,
                    locations,
                --div--;' . $ll . 'tabs.language,
                    sys_language_uid, l10n_parent,
                --div--;' . $ll . 'tabs.access,
                    hidden, --palette--;;access',
        ],
    ],
    'palettes' => [
        'classification' => ['showitem' => 'job_type, work_type, experience_level, --linebreak--, category, reference_id'],
        'dates' => ['label' => $ll . 'palette.dates', 'showitem' => 'published_at, apply_before, --linebreak--, start_date, end_date'],
        'workload' => ['label' => $ll . 'palette.workload', 'showitem' => 'workload_min, workload_max'],
        'salary' => ['label' => $ll . 'palette.salary', 'showitem' => 'salary_min, salary_max, --linebreak--, salary_currency, salary_interval'],
        'access' => ['showitem' => 'starttime, endtime'],
    ],
    'columns' => [
        'title' => [
            'label' => $ll . $table . '.title',
            'config' => ['type' => 'input', 'size' => 50, 'max' => 255, 'required' => true, 'eval' => 'trim'],
        ],
        'url' => [
            'label' => $ll . $table . '.url',
            'description' => $ll . $table . '.url.description',
            'config' => ['type' => 'link', 'allowedTypes' => ['page', 'url']],
        ],
        'description' => [
            'label' => $ll . $table . '.description',
            'description' => $ll . $table . '.description.description',
            'config' => ['type' => 'text', 'rows' => 8, 'cols' => 60, 'max' => 1000],
        ],
        'tags' => [
            'label' => $ll . $table . '.tags',
            'description' => $ll . $table . '.tags.description',
            'config' => ['type' => 'input', 'size' => 50, 'max' => 600, 'eval' => 'trim'],
        ],
        'category' => [
            'label' => $ll . $table . '.category',
            'config' => ['type' => 'input', 'size' => 30, 'max' => 255, 'eval' => 'trim'],
        ],
        'reference_id' => [
            'label' => $ll . $table . '.reference_id',
            'l10n_mode' => 'exclude',
            'config' => ['type' => 'input', 'size' => 20, 'max' => 255, 'eval' => 'trim'],
        ],
        'job_type' => [
            'label' => $ll . $table . '.job_type',
            ...$excluded,
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => array_slice($items('job_type', ['permanent', 'contract', 'internship', 'apprenticeship', 'temporary', 'volunteer', 'freelance']), 1),
                'default' => 'permanent',
            ],
        ],
        'work_type' => [
            'label' => $ll . $table . '.work_type',
            ...$excluded,
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $items('work_type', ['on-site', 'hybrid', 'remote'])],
        ],
        'experience_level' => [
            'label' => $ll . $table . '.experience_level',
            ...$excluded,
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $items('experience_level', ['junior', 'mid', 'senior', 'lead', 'manager', 'director', 'executive'])],
        ],
        'published_at' => [
            'label' => $ll . $table . '.published_at',
            'description' => $ll . $table . '.published_at.description',
            ...$excluded,
            'config' => ['type' => 'datetime', 'format' => 'date', 'dbType' => 'date', 'nullable' => true],
        ],
        'apply_before' => [
            'label' => $ll . $table . '.apply_before',
            ...$excluded,
            'config' => ['type' => 'datetime', 'format' => 'date', 'dbType' => 'date', 'nullable' => true],
        ],
        'start_date' => [
            'label' => $ll . $table . '.start_date',
            ...$excluded,
            'config' => ['type' => 'datetime', 'format' => 'date', 'dbType' => 'date', 'nullable' => true],
        ],
        'end_date' => [
            'label' => $ll . $table . '.end_date',
            ...$excluded,
            'config' => ['type' => 'datetime', 'format' => 'date', 'dbType' => 'date', 'nullable' => true],
        ],
        'workload_min' => [
            'label' => $ll . $table . '.workload_min',
            ...$excluded,
            'config' => ['type' => 'number', 'size' => 5, 'range' => ['lower' => 0, 'upper' => 100], 'nullable' => true],
        ],
        'workload_max' => [
            'label' => $ll . $table . '.workload_max',
            ...$excluded,
            'config' => ['type' => 'number', 'size' => 5, 'range' => ['lower' => 0, 'upper' => 100], 'nullable' => true],
        ],
        'salary_min' => [
            'label' => $ll . $table . '.salary_min',
            ...$excluded,
            'config' => ['type' => 'number', 'format' => 'decimal', 'size' => 10, 'nullable' => true],
        ],
        'salary_max' => [
            'label' => $ll . $table . '.salary_max',
            ...$excluded,
            'config' => ['type' => 'number', 'format' => 'decimal', 'size' => 10, 'nullable' => true],
        ],
        'salary_currency' => [
            'label' => $ll . $table . '.salary_currency',
            ...$excluded,
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                // Extend as needed; any ISO 4217 code is valid in oJobPub.
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'CHF', 'value' => 'CHF'],
                    ['label' => 'EUR', 'value' => 'EUR'],
                    ['label' => 'USD', 'value' => 'USD'],
                    ['label' => 'GBP', 'value' => 'GBP'],
                ],
            ],
        ],
        'salary_interval' => [
            'label' => $ll . $table . '.salary_interval',
            ...$excluded,
            'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $items('salary_interval', ['hourly', 'daily', 'weekly', 'monthly', 'yearly']), 'default' => 'yearly'],
        ],
        'locations' => [
            'label' => $ll . $table . '.locations',
            'description' => $ll . $table . '.locations.description',
            'l10n_mode' => 'exclude',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_ojobpub_domain_model_location',
                'foreign_field' => 'job',
                'foreign_sortby' => 'sorting',
                'maxitems' => 20,
                'appearance' => [
                    'collapseAll' => false,
                    'useSortable' => true,
                    'showPossibleLocalizationRecords' => false,
                ],
            ],
        ],
    ],
];
