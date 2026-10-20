<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * Defaults and the reference date the Normalizer works with.
 */
final readonly class Context
{
    /**
     * @param string $defaultLanguage ISO 639-1, used when a job has no language
     * @param string $defaultCountry ISO 3166-1 alpha-2, used for locations without a country
     * @param array{city?: string, country?: string} $fallbackLocation used when a job has no location (usually the employer's)
     */
    public function __construct(
        public \DateTimeImmutable $today,
        public string $defaultLanguage = '',
        public string $defaultCountry = '',
        public array $fallbackLocation = [],
    ) {}
}
