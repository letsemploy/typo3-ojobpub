<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Functional\Source;

use LetsEmploy\OJobPub\Source\NativeSource;
use LetsEmploy\OJobPub\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class NativeSourceTest extends AbstractFunctionalTestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function jobs(): array
    {
        return iterator_to_array($this->get(NativeSource::class)->jobs($this->site()));
    }

    #[Test]
    public function readsPublishedRecordsOfTheStorageFolderOnly(): void
    {
        $jobs = $this->jobs();

        // Hidden (6), deleted (7), scheduled (8), ended (9), workspace (10) and other folder (11) are not read.
        // 12 and 13 are read; the Normalizer decides about them.
        self::assertSame(
            [1, 2, 3, 4, 5, 12, 13],
            array_map(static fn(string $key): int => (int)substr($key, strlen(NativeSource::TABLE) + 1), array_keys($jobs)),
        );
    }

    #[Test]
    public function translationTakesTextFromTheTranslationAndTheRestFromTheDefaultRecord(): void
    {
        $jobs = $this->jobs();
        $en = $jobs[NativeSource::TABLE . ':1'];
        $de = $jobs[NativeSource::TABLE . ':2'];

        self::assertSame(['Carpenter', 'en'], [$en['title'], $en['language']]);
        self::assertSame(['Zimmermann/Zimmerin', 'de'], [$de['title'], $de['language']]);
        self::assertSame('Wir suchen eine Fachperson für Holzbau.', $de['description']);
        self::assertSame($en['salary'], $de['salary']);
        self::assertSame($en['jobType'], $de['jobType']);
        self::assertSame([['city' => 'Bern', 'country' => 'CH'], ['city' => 'Thun', 'country' => 'CH']], $de['locations']);
    }

    #[Test]
    public function allLanguagesRecordIsPublishedOnceAsDefaultLanguage(): void
    {
        $job = $this->jobs()[NativeSource::TABLE . ':5'];

        self::assertSame('en', $job['language']);
        self::assertSame([['city' => 'Berlin', 'country' => 'DE']], $job['locations']);
    }

    #[Test]
    public function urlsComeFromTheLinkOrTheDetailPage(): void
    {
        $jobs = $this->jobs();
        $url = static fn(int $uid): string => $jobs[NativeSource::TABLE . ':' . $uid]['url'];

        self::assertSame('https://example.com/jobs/site-manager', $url(3));
        self::assertSame('http://localhost/jobs', $url(4));
        self::assertStringStartsWith('http://localhost/jobs?', $url(1));
        self::assertStringContainsString('tx_ojobpub_detail%5Bjob%5D=1', $url(1));
        self::assertStringStartsWith('http://localhost/de/jobs?', $url(2));
        self::assertStringContainsString('tx_ojobpub_detail%5Bjob%5D=2', $url(2));
        self::assertSame('', $url(12), 'link to a missing page');
    }

    #[Test]
    public function noStorageFolderMeansNoJobs(): void
    {
        $this->writeSite(['native.storagePids' => '']);

        self::assertSame([], $this->jobs());
    }
}
