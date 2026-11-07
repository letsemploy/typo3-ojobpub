<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Functional;

use LetsEmploy\OJobPub\Service\FeedService;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TYPO3 with this extension, the pages and jobs of Fixtures/ and a site "main"
 * on http://localhost/ (en /, de /de/) with the oJobPub set. See Fixtures/jobs.csv
 * for what each job is meant to test.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected const SITE = 'main';
    protected const DETAIL_PAGE = 2;
    protected const STORAGE_FOLDER = 3;

    protected array $testExtensionsToLoad = ['letsemploy/typo3-ojobpub'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/jobs.csv');
        $this->writeSite();
    }

    /**
     * Writes config/sites/main. $settings are merged into the default ojobpub settings
     * (dotted keys, e.g. ['employer.name' => '']).
     *
     * @param array<string, mixed> $settings
     */
    protected function writeSite(array $settings = []): void
    {
        $ojobpub = [
            'enabled' => true,
            'defaults' => ['country' => 'CH'],
            'employer' => ['name' => 'Example Ltd', 'city' => 'Bern', 'country' => 'CH', 'industry' => 'Construction'],
            'native' => ['storagePids' => (string)self::STORAGE_FOLDER, 'detailPid' => self::DETAIL_PAGE],
        ];
        foreach ($settings as $path => $value) {
            $target = &$ojobpub;
            foreach (explode('.', $path) as $key) {
                $target = &$target[$key];
            }
            $target = $value;
            unset($target);
        }

        $dir = Environment::getConfigPath() . '/sites/' . self::SITE;
        GeneralUtility::mkdir_deep($dir);
        file_put_contents($dir . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'dependencies' => ['letsemploy/ojobpub'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'enabled' => true, 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'enabled' => true, 'locale' => 'de_CH.UTF-8', 'base' => '/de/', 'fallbackType' => 'strict'],
            ],
        ], 99, 2));
        file_put_contents($dir . '/settings.yaml', Yaml::dump(['ojobpub' => $ojobpub], 99, 2));

        $cacheManager = $this->get(CacheManager::class);
        $cacheManager->getCache('core')->flush();
        $cacheManager->getCache('runtime')->flush();
    }

    protected function site(): Site
    {
        return $this->get(SiteFinder::class)->getAllSites(false)[self::SITE];
    }

    protected function feedService(): FeedService
    {
        return $this->get(FeedService::class);
    }

    protected function assertSchemaValid(string $json): void
    {
        $schemaFile = getenv('OJOBPUB_SCHEMA') ?: dirname(__DIR__, 3) . '/schema/v1/ojobpub.json';
        if (!is_file($schemaFile)) {
            self::markTestSkipped('Schema not found. Clone github.com/letsemploy/schema next to this repo or set OJOBPUB_SCHEMA.');
        }
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $result = $validator->validate(json_decode($json), (string)file_get_contents($schemaFile));
        self::assertTrue($result->isValid(), print_r($result->error()?->message(), true));
    }
}
