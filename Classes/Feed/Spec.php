<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * Constants of oJobPub 1.0, taken from https://docs.letsemploy.org/ojobpub/specification/
 * and https://github.com/letsemploy/schema/blob/main/v1/ojobpub.json.
 *
 * Keep this file in sync with the schema. It is the only place where limits and
 * enumerations live.
 */
final class Spec
{
    public const VERSION = '1.0';
    public const WELL_KNOWN_PATH = '/.well-known/ojobpub.json';

    public const MAX_NAME = 255;
    public const MAX_TITLE = 255;
    public const MAX_CATEGORY = 255;
    public const MAX_REFERENCE_ID = 255;
    public const MAX_INDUSTRY = 255;
    public const MAX_DESCRIPTION = 1000;
    public const MAX_TAGS = 16;
    public const MAX_TAG_LENGTH = 28;

    public const JOB_TYPES = ['permanent', 'contract', 'internship', 'apprenticeship', 'temporary', 'volunteer', 'freelance'];
    public const WORK_TYPES = ['remote', 'on-site', 'hybrid'];
    public const EXPERIENCE_LEVELS = ['junior', 'mid', 'senior', 'lead', 'manager', 'director', 'executive'];
    public const SALARY_INTERVALS = ['hourly', 'daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Order of keys in a serialized job. Not required by the spec, but a stable
     * order keeps the content hash (and therefore lastUpdated/ETag) stable.
     */
    public const JOB_KEY_ORDER = [
        'title', 'language', 'publishedAt', 'jobType', 'locations', 'url',
        'description', 'category', 'referenceId', 'applyBefore', 'startDate', 'endDate',
        'workType', 'experienceLevel', 'workLoad', 'salary', 'tags',
    ];

    private function __construct() {}
}
