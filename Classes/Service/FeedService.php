<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Service;

use LetsEmploy\OJobPub\Feed\Builder;
use LetsEmploy\OJobPub\Feed\Context;
use LetsEmploy\OJobPub\Feed\InvalidEmployer;
use LetsEmploy\OJobPub\Source\NativeSource;
use LetsEmploy\OJobPub\Source\SourceRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Builds, caches and tracks lastUpdated of the feed of one site.
 *
 * lastUpdated only changes when the normalized content changes: the content
 * hash is compared with the one stored in sys_registry. The ETag is derived
 * from the same hash, so consumers get 304 until something really changed.
 */
final class FeedService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CACHE_TAG = 'ojobpub';
    private const REGISTRY_NAMESPACE = 'tx_ojobpub';

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly FrontendInterface $cache,
        private readonly Registry $registry,
        private readonly Builder $builder = new Builder(),
    ) {}

    public function isEnabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('ojobpub.enabled', false);
    }

    /**
     * @return FeedResult|null null when the feed is disabled or the employer is not configured
     */
    public function get(Site $site, bool $useCache = true): ?FeedResult
    {
        if (!$this->isEnabled($site)) {
            return null;
        }
        $cacheId = $this->cacheIdentifier($site);
        if ($useCache && is_array($entry = $this->cache->get($cacheId))) {
            return FeedResult::fromCacheEntry($entry);
        }

        $settings = $site->getSettings();
        $source = $this->sources->get((string)$settings->get('ojobpub.source', NativeSource::ID));
        $now = new \DateTimeImmutable();
        $employer = [
            'name' => $settings->get('ojobpub.employer.name', ''),
            'location' => [
                'city' => $settings->get('ojobpub.employer.city', ''),
                'country' => $settings->get('ojobpub.employer.country', ''),
            ],
            'industry' => $settings->get('ojobpub.employer.industry', ''),
            'url' => $settings->get('ojobpub.employer.url', '') ?: (string)$site->getBase(),
        ];
        $context = new Context(
            $now,
            $site->getDefaultLanguage()->getLocale()->getLanguageCode(),
            (string)$settings->get('ojobpub.defaults.country', '') ?: (string)$settings->get('ojobpub.employer.country', ''),
        );

        try {
            $build = $this->builder->build($employer, $source->jobs($site), $context);
        } catch (InvalidEmployer $e) {
            $this->logger?->warning('oJobPub feed of site "{site}" not served: {reason}', ['site' => $site->getIdentifier(), 'reason' => $e->getMessage()]);
            return null;
        }

        $lastUpdated = $this->lastUpdated($site, $build->contentHash, $now);
        $result = new FeedResult(
            $build->toJson($lastUpdated),
            '"' . substr($build->contentHash, 0, 32) . '"',
            $lastUpdated,
            count($build->jobs),
            $build->issues,
            $source->id(),
        );

        $this->cache->set(
            $cacheId,
            $result->toCacheEntry(),
            [self::CACHE_TAG, $this->siteTag($site)],
            $this->lifetime($site, $now),
        );

        return $result;
    }

    public function flush(?Site $site = null): void
    {
        $this->cache->flushByTag($site === null ? self::CACHE_TAG : $this->siteTag($site));
    }

    /**
     * Site identifiers may contain dots, which cache tags do not allow.
     */
    private function siteTag(Site $site): string
    {
        return self::CACHE_TAG . '_' . sha1($site->getIdentifier());
    }

    private function lastUpdated(Site $site, string $hash, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $key = 'state:' . $site->getIdentifier();
        $state = $this->registry->get(self::REGISTRY_NAMESPACE, $key);
        if (is_array($state) && ($state['hash'] ?? '') === $hash) {
            return new \DateTimeImmutable('@' . (int)$state['lastUpdated']);
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, $key, ['hash' => $hash, 'lastUpdated' => $now->getTimestamp()]);

        return $now;
    }

    /**
     * Settings are part of the identifier, so a changed setting never serves a stale feed.
     */
    private function cacheIdentifier(Site $site): string
    {
        $settings = array_filter(
            $site->getSettings()->getMap(),
            static fn(string $key): bool => str_starts_with($key, 'ojobpub.'),
            ARRAY_FILTER_USE_KEY,
        );

        return 'feed_' . sha1($site->getIdentifier() . json_encode($settings) . date('Y-m-d'));
    }

    /**
     * At most the configured lifetime, and never past midnight, so jobs whose
     * deadline has passed drop out without an edit.
     */
    private function lifetime(Site $site, \DateTimeImmutable $now): int
    {
        $configured = max(60, (int)$site->getSettings()->get('ojobpub.cacheLifetime', 3600));
        $untilMidnight = $now->modify('tomorrow')->getTimestamp() - $now->getTimestamp();

        return max(60, min($configured, $untilMidnight));
    }
}
