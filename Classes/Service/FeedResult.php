<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Service;

use LetsEmploy\OJobPub\Feed\Issue;

final readonly class FeedResult
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        public string $json,
        public string $etag,
        public \DateTimeImmutable $lastUpdated,
        public int $jobCount,
        public array $issues,
        public string $sourceId,
    ) {}

    public function toCacheEntry(): array
    {
        return [
            'json' => $this->json,
            'etag' => $this->etag,
            'lastUpdated' => $this->lastUpdated->getTimestamp(),
            'jobCount' => $this->jobCount,
            'issues' => array_map(static fn(Issue $i) => [$i->jobKey, $i->title, $i->reason], $this->issues),
            'sourceId' => $this->sourceId,
        ];
    }

    public static function fromCacheEntry(array $entry): self
    {
        return new self(
            $entry['json'],
            $entry['etag'],
            new \DateTimeImmutable('@' . $entry['lastUpdated']),
            $entry['jobCount'],
            array_map(static fn(array $i) => new Issue(...$i), $entry['issues']),
            $entry['sourceId'],
        );
    }
}
