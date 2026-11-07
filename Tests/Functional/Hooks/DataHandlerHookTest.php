<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Functional\Hooks;

use LetsEmploy\OJobPub\Source\NativeSource;
use LetsEmploy\OJobPub\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DataHandlerHookTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function dataHandler(array $data, array $commands = []): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
    }

    private function cachedJson(): string
    {
        return (string)$this->feedService()->get($this->site())?->json;
    }

    #[Test]
    public function savingAJobFlushesTheFeed(): void
    {
        self::assertStringContainsString('"Site Manager"', $this->cachedJson());

        $this->dataHandler([NativeSource::TABLE => [3 => ['title' => 'Head of Sites']]]);

        self::assertStringContainsString('"Head of Sites"', $this->cachedJson());
    }

    #[Test]
    public function deletingAJobFlushesTheFeed(): void
    {
        self::assertStringContainsString('example.com/jobs/site-manager', $this->cachedJson());

        $this->dataHandler([], [NativeSource::TABLE => [3 => ['delete' => 1]]]);

        self::assertStringNotContainsString('example.com/jobs/site-manager', $this->cachedJson());
    }

    #[Test]
    public function savingALocationFlushesTheFeed(): void
    {
        self::assertStringContainsString('"Zürich"', $this->cachedJson());

        $this->dataHandler([NativeSource::LOCATION_TABLE => [3 => ['city' => 'Winterthur']]]);

        self::assertStringContainsString('"Winterthur"', $this->cachedJson());
    }

    #[Test]
    public function savingOtherRecordsKeepsTheFeed(): void
    {
        $before = $this->cachedJson();
        // Without DataHandler: only a flush would make this visible.
        $this->getConnectionPool()->getConnectionForTable(NativeSource::TABLE)
            ->update(NativeSource::TABLE, ['title' => 'Head of Sites'], ['uid' => 3]);

        $this->dataHandler(['pages' => [4 => ['title' => 'Archive']]]);

        self::assertSame($before, $this->cachedJson());
    }
}
