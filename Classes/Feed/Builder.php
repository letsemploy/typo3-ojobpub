<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * Assembles a feed from raw employer data and raw jobs: normalizes, sorts and hashes.
 */
final class Builder
{
    public function __construct(
        private readonly Normalizer $normalizer = new Normalizer(),
    ) {}

    /**
     * @param iterable<string|int, array<string, mixed>> $rawJobs keyed by a source-specific job key (e.g. "tx_ojobpub_domain_model_job:12")
     *
     * @throws InvalidEmployer
     */
    public function build(array $rawEmployer, iterable $rawJobs, Context $context): BuildResult
    {
        $employer = $this->normalizer->employer($rawEmployer, $context);
        $context = new Context(
            $context->today,
            $context->defaultLanguage,
            $context->defaultCountry,
            $context->fallbackLocation !== [] ? $context->fallbackLocation : $employer['location'],
        );

        $jobs = [];
        $issues = [];
        $urls = [];
        foreach ($rawJobs as $key => $raw) {
            $result = $this->normalizer->job((string)$key, $raw, $context);
            if ($result instanceof Issue) {
                $issues[] = $result;
                continue;
            }
            // Two entries with the same URL are almost always a source bug (e.g. a translation without its own page).
            if (isset($urls[$result['url']])) {
                $issues[] = new Issue((string)$key, $result['title'], sprintf('Same URL as job "%s".', $urls[$result['url']]));
                continue;
            }
            $urls[$result['url']] = $result['title'];
            $jobs[] = $this->ordered($result);
        }

        usort($jobs, static fn(array $a, array $b): int =>
            [$b['publishedAt'], $a['title'], $a['url']] <=> [$a['publishedAt'], $b['title'], $b['url']]);

        return new BuildResult(
            $employer,
            $jobs,
            hash('sha256', Json::encode(['employer' => $employer, 'jobs' => $jobs])),
            $issues,
        );
    }

    private function ordered(array $job): array
    {
        $ordered = [];
        foreach (Spec::JOB_KEY_ORDER as $key) {
            if (array_key_exists($key, $job)) {
                $ordered[$key] = $job[$key];
            }
        }

        return $ordered;
    }
}
