<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Unit\Feed;

use LetsEmploy\OJobPub\Feed\Builder;
use LetsEmploy\OJobPub\Feed\Context;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private const EMPLOYER = ['name' => 'Acme AG', 'location' => ['city' => 'Solothurn', 'country' => 'CH'], 'url' => 'https://example.ch'];

    private function context(): Context
    {
        return new Context(new \DateTimeImmutable('2026-10-20'), 'de', 'CH');
    }

    private function jobs(): array
    {
        return [
            'a' => ['title' => 'B Job', 'language' => 'de', 'publishedAt' => '2026-10-01', 'jobType' => 'permanent', 'url' => 'https://example.ch/b'],
            'b' => ['title' => 'A Job', 'language' => 'de', 'publishedAt' => '2026-10-01', 'jobType' => 'contract', 'url' => 'https://example.ch/a'],
            'c' => ['title' => 'Newest', 'language' => 'en', 'publishedAt' => '2026-10-10', 'jobType' => 'internship', 'url' => 'https://example.ch/c',
                'salary' => ['min' => '5k', 'currency' => 'CHF', 'interval' => 'monthly'], 'tags' => ['php', 'typo3']],
            'd' => ['title' => 'Duplicate URL', 'language' => 'de', 'publishedAt' => '2026-10-02', 'jobType' => 'permanent', 'url' => 'https://example.ch/a'],
            'e' => ['title' => 'Expired', 'language' => 'de', 'publishedAt' => '2026-09-01', 'jobType' => 'permanent', 'url' => 'https://example.ch/e', 'applyBefore' => '2026-10-01'],
        ];
    }

    #[Test]
    public function jobsAreSortedAndIssuesCollected(): void
    {
        $result = (new Builder())->build(self::EMPLOYER, $this->jobs(), $this->context());

        // publishedAt descending, then title ascending
        self::assertSame(['Newest', 'A Job', 'B Job'], array_column($result->jobs, 'title'));
        // d duplicates the URL of b, e has expired
        self::assertSame(['d', 'e'], array_map(static fn($i) => $i->jobKey, $result->issues));
    }

    #[Test]
    public function hashIsStableAndIndependentOfInputOrder(): void
    {
        $builder = new Builder();
        $one = $builder->build(self::EMPLOYER, $this->jobs(), $this->context());
        $two = $builder->build(self::EMPLOYER, array_reverse($this->jobs(), true), $this->context());

        // Note: with duplicate URLs the first one wins, so reversing changes which job survives.
        // Compare with a set without duplicates.
        $jobs = $this->jobs();
        unset($jobs['d']);
        self::assertSame(
            $builder->build(self::EMPLOYER, $jobs, $this->context())->contentHash,
            $builder->build(self::EMPLOYER, array_reverse($jobs, true), $this->context())->contentHash,
        );
        self::assertNotSame($one->contentHash, $two->contentHash);
    }

    #[Test]
    public function emptyFeedEncodesObjectsAndLists(): void
    {
        $json = (new Builder())->build(['name' => 'Acme'], [], $this->context())
            ->toJson(new \DateTimeImmutable('2026-10-20T08:00:00+02:00'));

        self::assertSame('{"version":"1.0","lastUpdated":"2026-10-20T06:00:00Z","employer":{"name":"Acme","location":{}},"jobs":[]}', $json);
    }

    #[Test]
    public function documentValidatesAgainstSchema(): void
    {
        $schemaFile = getenv('OJOBPUB_SCHEMA') ?: dirname(__DIR__, 4) . '/schema/v1/ojobpub.json';
        if (!is_file($schemaFile) || !class_exists(Validator::class)) {
            self::markTestSkipped('Schema not found. Clone github.com/letsemploy/schema next to this repo or set OJOBPUB_SCHEMA.');
        }

        $json = (new Builder())->build(self::EMPLOYER, $this->jobs(), $this->context())->toJson(new \DateTimeImmutable());
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $result = $validator->validate(json_decode($json), file_get_contents($schemaFile));

        self::assertTrue($result->isValid(), print_r($result->error()?->message(), true));
    }
}
