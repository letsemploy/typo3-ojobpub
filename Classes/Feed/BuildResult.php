<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * A normalized feed without lastUpdated. The hash covers employer and jobs, so
 * a caller can keep lastUpdated (and the ETag) stable while the content is.
 */
final readonly class BuildResult
{
    /**
     * @param array<string, mixed> $employer
     * @param list<array<string, mixed>> $jobs
     * @param list<Issue> $issues
     */
    public function __construct(
        public array $employer,
        public array $jobs,
        public string $contentHash,
        public array $issues,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDocument(\DateTimeInterface $lastUpdated): array
    {
        return [
            'version' => Spec::VERSION,
            'lastUpdated' => \DateTimeImmutable::createFromInterface($lastUpdated)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'employer' => $this->employer,
            'jobs' => $this->jobs,
        ];
    }

    public function toJson(\DateTimeInterface $lastUpdated, bool $pretty = false): string
    {
        return Json::encode($this->toDocument($lastUpdated), $pretty);
    }
}
