<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Source;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * A place jobs come from: the extension's own records, or another job extension.
 *
 * Adapters map raw data to oJobPub field names without validating it; the
 * Normalizer does all validation. Implement this interface in any extension
 * and the class is picked up automatically (tag "ojobpub.source"). For the
 * same id(), the service registered last wins, so an adapter can be replaced.
 *
 * Expected keys per job (all optional for the adapter, the Normalizer decides):
 * title, language, publishedAt, jobType, locations (list of {city, country}),
 * url, description, category, referenceId, applyBefore, startDate, endDate,
 * workType, experienceLevel, workLoad {minPercentage, maxPercentage},
 * salary {min, max, currency, interval}, tags (list or comma-separated string).
 */
#[AutoconfigureTag('ojobpub.source')]
interface SourceInterface
{
    /**
     * Stable identifier, used in the site setting "ojobpub.source".
     */
    public function id(): string;

    public function label(): string;

    /**
     * False when the extension the adapter reads from is not installed.
     */
    public function isAvailable(): bool;

    /**
     * @return iterable<string, array<string, mixed>> keyed by a stable job key, e.g. "tx_ojobpub_domain_model_job:12"
     */
    public function jobs(Site $site): iterable;

    /**
     * Database tables whose changes must invalidate the cached feed.
     *
     * @return list<string>
     */
    public function tables(): array;
}
