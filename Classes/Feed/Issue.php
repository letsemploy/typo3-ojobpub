<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * A job that could not be published, with the reason. Shown to editors
 * instead of breaking the whole feed.
 */
final readonly class Issue
{
    public function __construct(
        public string $jobKey,
        public string $title,
        public string $reason,
    ) {}
}
