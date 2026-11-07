<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Functional\Service;

use LetsEmploy\OJobPub\Feed\Issue;
use LetsEmploy\OJobPub\Source\NativeSource;
use LetsEmploy\OJobPub\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Registry;

final class FeedServiceTest extends AbstractFunctionalTestCase
{
    /**
     * Changes a record without DataHandler, so no cache is flushed.
     */
    private function renameJob(int $uid, string $title): void
    {
        $this->getConnectionPool()->getConnectionForTable(NativeSource::TABLE)
            ->update(NativeSource::TABLE, ['title' => $title], ['uid' => $uid]);
    }

    #[Test]
    public function buildsTheFeedAndReportsIssues(): void
    {
        $feed = $this->feedService()->get($this->site(), false);

        self::assertNotNull($feed);
        self::assertSame(5, $feed->jobCount);
        self::assertSame(NativeSource::ID, $feed->sourceId);
        $issues = array_combine(
            array_map(static fn(Issue $issue): string => $issue->jobKey, $feed->issues),
            array_map(static fn(Issue $issue): string => $issue->reason, $feed->issues),
        );
        self::assertSame([NativeSource::TABLE . ':12', NativeSource::TABLE . ':13'], array_keys($issues));
        self::assertStringContainsString('deadline', $issues[NativeSource::TABLE . ':13']);
    }

    #[Test]
    public function resultIsCachedUntilFlushed(): void
    {
        $service = $this->feedService();
        $first = $service->get($this->site());
        $this->renameJob(3, 'Head of Sites');

        self::assertSame($first?->json, $service->get($this->site())?->json);
        self::assertStringContainsString('Head of Sites', (string)$service->get($this->site(), false)?->json);

        $service->flush();
        self::assertStringContainsString('Head of Sites', (string)$service->get($this->site())?->json);
    }

    #[Test]
    public function changedSettingsAreNotServedFromTheCache(): void
    {
        $this->feedService()->get($this->site());
        $this->renameJob(3, 'Head of Sites');
        $this->writeSite(['employer.industry' => 'Timber construction']);

        $json = (string)$this->feedService()->get($this->site())?->json;

        self::assertStringContainsString('Timber construction', $json);
        self::assertStringContainsString('Head of Sites', $json);
    }

    #[Test]
    public function lastUpdatedOnlyChangesWithTheContent(): void
    {
        $service = $this->feedService();
        $registry = $this->get(Registry::class);
        $first = $service->get($this->site(), false);
        $state = $registry->get('tx_ojobpub', 'state:' . self::SITE);
        self::assertIsArray($state);
        $registry->set('tx_ojobpub', 'state:' . self::SITE, ['hash' => $state['hash'], 'lastUpdated' => 1000000000]);

        $same = $service->get($this->site(), false);
        self::assertSame(1000000000, $same?->lastUpdated->getTimestamp());
        self::assertSame($first?->etag, $same->etag);
        self::assertStringContainsString('"lastUpdated":"2001-09-09T01:46:40', (string)$same->json);

        $this->renameJob(3, 'Head of Sites');
        $changed = $service->get($this->site(), false);
        self::assertNotSame(1000000000, $changed?->lastUpdated->getTimestamp());
        self::assertNotSame($first->etag, $changed->etag);
    }
}
