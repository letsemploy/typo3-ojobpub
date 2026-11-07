<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPubDev\Command;

use LetsEmploy\OJobPub\Source\NativeSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Replaces all job records with sample jobs covering the edge cases of the feed:
 * translations, "All languages", HTML and over-long descriptions, external and page
 * links, the detail-page fallback, a broken link, a duplicate URL, an expired, a
 * hidden and a scheduled job, and a job without location.
 *
 * Expected result: see the comment at each job. Run `make issues` to see why jobs
 * were left out.
 */
#[AsCommand(name: 'ojobpub-dev:seed', description: 'Replace all oJobPub job records with sample jobs.')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);

        $site = $this->siteFinder->getSiteByIdentifier('main');
        $folder = (int)$site->getSettings()->get('ojobpub.native.storagePids');
        $detail = (int)$site->getSettings()->get('ojobpub.native.detailPid');

        foreach ([NativeSource::TABLE, NativeSource::LOCATION_TABLE] as $table) {
            $this->connectionPool->getConnectionForTable($table)->truncate($table);
        }
        $this->translatePages([$site->getRootPageId(), $detail], 1);

        $day = 86400;
        $date = static fn(int $days): string => date('Y-m-d', time() + $days * $day);
        $longText = str_repeat('We build timber houses, roofs and facades for private and public clients. ', 20);

        $jobs = [
            // Complete job, translated; URL from the detail page; one tag is too long and dropped.
            'carpenter' => [
                'job' => [
                    'title' => 'Carpenter',
                    'description' => '<p>We are looking for an <strong>experienced</strong> carpenter.</p><script>alert(1)</script>',
                    'tags' => 'Timber, CNC, A tag that is much longer than twenty-eight characters',
                    'category' => 'Construction',
                    'reference_id' => 'J-100',
                    'job_type' => 'permanent',
                    'work_type' => 'on-site',
                    'experience_level' => 'mid',
                    'published_at' => $date(-3),
                    'apply_before' => $date(30),
                    'start_date' => $date(60),
                    'workload_min' => 80,
                    'workload_max' => 100,
                    'salary_min' => 75000,
                    'salary_max' => 90000,
                    'salary_currency' => 'CHF',
                    'salary_interval' => 'yearly',
                ],
                'locations' => [['city' => 'Bern', 'country' => 'CH'], ['city' => 'Thun', 'country' => 'CH']],
                'translation' => [
                    'title' => 'Zimmermann/Zimmerin EFZ',
                    'description' => '<p>Wir suchen eine <strong>erfahrene</strong> Fachperson für Holzbau.</p>',
                    'tags' => 'Holzbau, CNC',
                    'category' => 'Bau',
                ],
            ],
            // External link, translated with its own URL.
            'site-manager' => [
                'job' => [
                    'title' => 'Site Manager',
                    'description' => 'Lead our construction sites in the Zurich area.',
                    'url' => 'https://example.com/jobs/site-manager',
                    'job_type' => 'permanent',
                    'work_type' => 'hybrid',
                    'experience_level' => 'senior',
                ],
                'locations' => [['city' => 'Zürich', 'country' => 'CH']],
                'translation' => [
                    'title' => 'Bauführer/in',
                    'url' => 'https://example.com/de/jobs/bauleiter',
                ],
            ],
            // Page link, not translated: English entry only.
            'project-lead' => [
                'job' => [
                    'title' => 'Project Lead Timber Construction',
                    'description' => 'From the offer to the handover.',
                    'url' => 't3://page?uid=' . $detail,
                    'job_type' => 'permanent',
                ],
                'locations' => [['city' => 'Biel/Bienne', 'country' => 'CH']],
            ],
            // No location: gets the employer's headquarters. Description is truncated to 1000 characters.
            'apprentice' => [
                'job' => [
                    'title' => 'Apprentice Carpenter',
                    'description' => $longText,
                    'job_type' => 'apprenticeship',
                    'apply_before' => $date(0),
                ],
            ],
            // "All languages": published once, as English.
            'developer' => [
                'job' => [
                    'sys_language_uid' => -1,
                    'title' => 'Software Developer (Remote)',
                    'description' => 'Build our planning tools.',
                    'url' => 'https://example.com/jobs/developer',
                    'job_type' => 'contract',
                    'work_type' => 'remote',
                    'salary_min' => 6000,
                    'salary_max' => 7500,
                    'salary_currency' => 'EUR',
                    'salary_interval' => 'monthly',
                ],
                'locations' => [['city' => 'Berlin', 'country' => 'DE']],
            ],
            // Issue: same URL as the site manager.
            'duplicate' => [
                'job' => [
                    'title' => 'Site Manager (duplicate)',
                    'url' => 'https://example.com/jobs/site-manager',
                ],
            ],
            // Issue: link to a page that does not exist, so no URL.
            'broken-link' => [
                'job' => ['title' => 'Broken link', 'url' => 't3://page?uid=999999'],
            ],
            // Left out: application deadline has passed.
            'expired' => [
                'job' => ['title' => 'Expired job', 'apply_before' => $date(-1)],
            ],
            // Left out: hidden record.
            'hidden' => [
                'job' => ['title' => 'Hidden job', 'hidden' => 1],
            ],
            // Left out until next week: start time in the future.
            'scheduled' => [
                'job' => ['title' => 'Scheduled job', 'starttime' => time() + 7 * $day],
            ],
        ];

        // NEW ids must not contain "_": DataHandler splits inline values at it (table_uid).
        $data = [NativeSource::TABLE => [], NativeSource::LOCATION_TABLE => []];
        $newIds = [];
        foreach ($jobs as $key => $job) {
            $newIds[$key] = StringUtility::getUniqueId('NEW');
            $locationIds = [];
            foreach ($job['locations'] ?? [] as $location) {
                $id = StringUtility::getUniqueId('NEW');
                $data[NativeSource::LOCATION_TABLE][$id] = ['pid' => $folder, ...$location];
                $locationIds[] = $id;
            }
            $data[NativeSource::TABLE][$newIds[$key]] = [
                'pid' => $folder,
                'locations' => implode(',', $locationIds),
                ...$job['job'],
            ];
        }
        $dataHandler = $this->process($data, []);

        foreach ($jobs as $key => $job) {
            if (!isset($job['translation'])) {
                continue;
            }
            $uid = (int)$dataHandler->substNEWwithIDs[$newIds[$key]];
            $localized = $this->process([], [NativeSource::TABLE => [$uid => ['localize' => 1]]]);
            $translationUid = (int)$localized->copyMappingArray_merged[NativeSource::TABLE][$uid];
            $this->process([NativeSource::TABLE => [$translationUid => $job['translation']]], []);
        }

        $output->writeln(sprintf('Created %d sample jobs in folder %d.', count($jobs), $folder));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $pages
     */
    private function translatePages(array $pages, int $languageId): void
    {
        foreach ($pages as $uid) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $exists = $qb->count('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($uid)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId)),
                )
                ->executeQuery()
                ->fetchOne();
            if (!$exists) {
                // Translations are created hidden and with a "[Translate to ...]" title and slug.
                $localized = $this->process([], ['pages' => [$uid => ['localize' => $languageId]]]);
                $page = BackendUtility::getRecord('pages', $uid, 'title, slug');
                $this->process(['pages' => [(int)$localized->copyMappingArray_merged['pages'][$uid] => ['hidden' => 0, ...$page]]], []);
            }
        }
    }

    private function process(array $data, array $commands): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(implode('; ', $dataHandler->errorLog));
        }

        return $dataHandler;
    }
}
