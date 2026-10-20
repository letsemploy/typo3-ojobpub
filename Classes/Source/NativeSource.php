<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Source;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Jobs stored as records of the extension's own table.
 *
 * Translations are separate records (sys_language_uid > 0). Text fields come
 * from the translation, everything else from the default-language record, so
 * editors only translate what is language dependent.
 */
final class NativeSource implements SourceInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const ID = 'native';
    public const TABLE = 'tx_ojobpub_domain_model_job';
    public const LOCATION_TABLE = 'tx_ojobpub_domain_model_location';

    /** Fields taken from a translation; all others come from the default-language record. */
    private const TRANSLATED_FIELDS = ['title', 'description', 'category', 'tags', 'url'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LinkService $linkService,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'oJobPub job records';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function tables(): array
    {
        return [self::TABLE, self::LOCATION_TABLE];
    }

    public function jobs(Site $site): iterable
    {
        $pids = GeneralUtility::intExplode(',', (string)$site->getSettings()->get('ojobpub.native.storagePids', ''), true);
        if ($pids === []) {
            // TODO: decide whether "no storage folder configured" should mean "whole page tree of this site".
            return;
        }
        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $languages[$language->getLanguageId()] = $language;
        }
        if ($languages === []) {
            return;
        }

        // -1 = "All languages": published once, as a default-language entry.
        $rows = $this->fetchJobs($pids, [...array_keys($languages), -1]);
        $defaults = [];
        foreach ($rows as &$row) {
            if ((int)$row['sys_language_uid'] === -1) {
                $row['sys_language_uid'] = 0;
            }
            if ((int)$row['sys_language_uid'] === 0) {
                $defaults[(int)$row['uid']] = $row;
            }
        }
        unset($row);
        if (!isset($languages[0])) {
            // TODO: sites without an enabled default language.
            return;
        }
        $locations = $this->fetchLocations(array_keys($defaults));

        foreach ($rows as $row) {
            $languageId = (int)$row['sys_language_uid'];
            $parentUid = $languageId === 0 ? (int)$row['uid'] : (int)$row['l10n_parent'];
            $base = $defaults[$parentUid] ?? null;
            if ($base === null) {
                // Translation without a published default record. TODO: support "free mode" translations.
                continue;
            }
            $merged = $base;
            foreach (self::TRANSLATED_FIELDS as $field) {
                if ($languageId !== 0 && (string)$row[$field] !== '') {
                    $merged[$field] = $row[$field];
                }
            }

            yield self::TABLE . ':' . $row['uid'] => $this->map(
                $merged,
                $locations[$parentUid] ?? [],
                $site,
                $languages[$languageId],
                (int)$row['uid'],
            );
        }
    }

    /**
     * @param list<array{city: string, country: string}> $locations
     */
    private function map(array $row, array $locations, Site $site, SiteLanguage $language, int $uid): array
    {
        return [
            'title' => $row['title'],
            'language' => $language->getLocale()->getLanguageCode(),
            'publishedAt' => $row['published_at'] ?: (int)$row['crdate'],
            'jobType' => $row['job_type'],
            'locations' => $locations,
            'url' => $this->url((string)$row['url'], $site, $language, $uid),
            'description' => $row['description'],
            'category' => $row['category'],
            'referenceId' => $row['reference_id'],
            'applyBefore' => $row['apply_before'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'workType' => $row['work_type'],
            'experienceLevel' => $row['experience_level'],
            'workLoad' => [
                'minPercentage' => $row['workload_min'] ?: null,
                'maxPercentage' => $row['workload_max'] ?: null,
            ],
            'salary' => [
                'min' => (float)$row['salary_min'] > 0 ? $row['salary_min'] : null,
                'max' => (float)$row['salary_max'] > 0 ? $row['salary_max'] : null,
                'currency' => $row['salary_currency'],
                'interval' => $row['salary_interval'],
            ],
            'tags' => $row['tags'],
        ];
    }

    /**
     * The record's own link (external URL or page), otherwise the configured detail page.
     */
    private function url(string $link, Site $site, SiteLanguage $language, int $uid): string
    {
        try {
            if ($link !== '') {
                $resolved = $this->linkService->resolve($link);
                return match ($resolved['type'] ?? '') {
                    LinkService::TYPE_URL => (string)$resolved['url'],
                    // TODO: keep link parameters and fragment (PageLinkHandler returns them separately).
                    LinkService::TYPE_PAGE => (int)$resolved['pageuid'] > 0
                        ? (string)$site->getRouter()->generateUri((int)$resolved['pageuid'], ['_language' => $language])
                        : '',
                    default => '',
                };
            }
            $detailPid = (int)$site->getSettings()->get('ojobpub.native.detailPid', 0);
            if ($detailPid > 0) {
                // TODO: ship a detail plugin and a route enhancer for these arguments.
                return (string)$site->getRouter()->generateUri(
                    $detailPid,
                    ['_language' => $language, 'tx_ojobpub_detail' => ['job' => $uid]],
                );
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('oJobPub: cannot build URL for job {uid}: {message}', ['uid' => $uid, 'message' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * @param list<int> $pids
     * @param list<int> $languageIds
     * @return list<array<string, mixed>>
     */
    private function fetchJobs(array $pids, array $languageIds): array
    {
        // Default restrictions (deleted, hidden, starttime, endtime) plus live workspace only.
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

        return $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->in('pid', $qb->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->in('sys_language_uid', $qb->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Locations of default-language records, grouped by job uid.
     *
     * @param list<int> $jobUids
     * @return array<int, list<array{city: string, country: string}>>
     */
    private function fetchLocations(array $jobUids): array
    {
        if ($jobUids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LOCATION_TABLE);
        $qb->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        $rows = $qb->select('job', 'city', 'country')
            ->from(self::LOCATION_TABLE)
            ->where(
                $qb->expr()->in('job', $qb->createNamedParameter($jobUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', 0),
            )
            ->orderBy('job')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $locations = [];
        foreach ($rows as $row) {
            $locations[(int)$row['job']][] = ['city' => (string)$row['city'], 'country' => (string)$row['country']];
        }

        return $locations;
    }
}
