<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPubDev\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the dev site: root page, detail page "Jobs", storage folder "Job records"
 * and config/sites/main with two languages and the oJobPub set. Idempotent.
 */
#[AsCommand(name: 'ojobpub-dev:site', description: 'Create the oJobPub dev site (pages, site config, settings).')]
final class SiteCommand extends Command
{
    public const ROOT_TITLE = 'Example Ltd';
    public const DETAIL_TITLE = 'Jobs';
    public const FOLDER_TITLE = 'Job records';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('base', null, InputOption::VALUE_REQUIRED, 'Site base, e.g. http://localhost:8014/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);

        $root = $this->page(0, self::ROOT_TITLE, ['is_siteroot' => 1, 'slug' => '/']);
        $detail = $this->page($root, self::DETAIL_TITLE, ['slug' => '/jobs']);
        $folder = $this->page($root, self::FOLDER_TITLE, ['doktype' => 254]);

        $dir = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($dir);
        file_put_contents($dir . '/config.yaml', Yaml::dump([
            'rootPageId' => $root,
            'base' => (string)$input->getOption('base'),
            'dependencies' => ['letsemploy/ojobpub'],
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'enabled' => true,
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
                [
                    'languageId' => 1,
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'locale' => 'de_CH.UTF-8',
                    'base' => '/de/',
                    'navigationTitle' => 'Deutsch',
                    'flag' => 'ch',
                    'fallbackType' => 'strict',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ], 99, 2));
        file_put_contents($dir . '/settings.yaml', Yaml::dump([
            'ojobpub' => [
                'enabled' => true,
                'defaults' => ['country' => 'CH'],
                'employer' => [
                    'name' => self::ROOT_TITLE,
                    'city' => 'Bern',
                    'country' => 'CH',
                    'industry' => 'Construction',
                ],
                'native' => [
                    'storagePids' => (string)$folder,
                    'detailPid' => $detail,
                ],
            ],
        ], 99, 2));

        $output->writeln(sprintf('Site "main": root %d, detail page %d, storage folder %d', $root, $detail, $folder));
        return Command::SUCCESS;
    }

    /**
     * Uid of the default-language page with this title below $pid, created if missing.
     */
    private function page(int $pid, string $title, array $fields): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $uid = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid)),
                $qb->expr()->eq('title', $qb->createNamedParameter($title)),
                $qb->expr()->eq('sys_language_uid', 0),
            )
            ->executeQuery()
            ->fetchOne();
        if ($uid !== false) {
            return (int)$uid;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => ['NEW' => ['pid' => $pid, 'title' => $title, 'hidden' => 0, ...$fields]]], []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException('Cannot create page "' . $title . '": ' . implode('; ', $dataHandler->errorLog));
        }

        return (int)$dataHandler->substNEWwithIDs['NEW'];
    }
}
