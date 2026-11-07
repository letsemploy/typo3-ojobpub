<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Tests\Unit\Feed;

use LetsEmploy\OJobPub\Feed\Context;
use LetsEmploy\OJobPub\Feed\InvalidEmployer;
use LetsEmploy\OJobPub\Feed\Issue;
use LetsEmploy\OJobPub\Feed\Normalizer;
use LetsEmploy\OJobPub\Feed\Spec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;
    private Context $context;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
        $this->context = new Context(new \DateTimeImmutable('2026-10-20'), 'de', 'CH', ['city' => 'Solothurn', 'country' => 'CH']);
    }

    private function validJob(array $override = []): array
    {
        return array_replace([
            'title' => 'Java Entwickler:in',
            'language' => 'de_CH',
            'publishedAt' => '2026-10-01',
            'jobType' => 'permanent',
            'locations' => [['city' => 'Solothurn', 'country' => 'ch']],
            'url' => 'https://example.ch/jobs/java',
        ], $override);
    }

    #[Test]
    public function minimalJobIsNormalized(): void
    {
        $job = $this->normalizer->job('1', $this->validJob(), $this->context);

        self::assertSame([
            'title' => 'Java Entwickler:in',
            'language' => 'de',
            'publishedAt' => '2026-10-01',
            'jobType' => 'permanent',
            'locations' => [['city' => 'Solothurn', 'country' => 'CH']],
            'url' => 'https://example.ch/jobs/java',
        ], $job);
    }

    #[Test]
    public function expiredJobBecomesIssue(): void
    {
        $result = $this->normalizer->job('1', $this->validJob(['applyBefore' => '2026-10-19']), $this->context);
        self::assertInstanceOf(Issue::class, $result);

        $result = $this->normalizer->job('1', $this->validJob(['applyBefore' => '2026-10-20']), $this->context);
        self::assertIsArray($result);
    }

    #[Test]
    #[DataProvider('missingRequired')]
    public function missingRequiredFieldBecomesIssue(string $field, mixed $value): void
    {
        $context = new Context(new \DateTimeImmutable('2026-10-20'));
        self::assertInstanceOf(Issue::class, $this->normalizer->job('1', $this->validJob([$field => $value]), $context));
    }

    public static function missingRequired(): iterable
    {
        yield 'title' => ['title', '  '];
        yield 'language' => ['language', 'deutsch'];
        yield 'publishedAt' => ['publishedAt', '1970-01-01'];
        yield 'jobType' => ['jobType', 'fun'];
        yield 'url relative' => ['url', '/jobs/1'];
        yield 'url javascript' => ['url', 'javascript:alert(1)'];
        yield 'locations' => ['locations', []];
    }

    #[Test]
    public function missingLocationFallsBackToContext(): void
    {
        $job = $this->normalizer->job('1', $this->validJob(['locations' => []]), $this->context);
        self::assertSame([['city' => 'Solothurn', 'country' => 'CH']], $job['locations']);
    }

    #[Test]
    public function descriptionIsPlainTextAndLimited(): void
    {
        $job = $this->normalizer->job('1', $this->validJob([
            'description' => '<p>Hallo&nbsp;<b>Welt</b></p><script>x</script><a href="javascript:alert(1)">link</a>',
        ]), $this->context);
        self::assertStringNotContainsString('<', $job['description']);
        self::assertStringNotContainsString('javascript:', $job['description']);

        $long = $this->normalizer->description(str_repeat('Wort ', 400));
        self::assertLessThanOrEqual(Spec::MAX_DESCRIPTION, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    #[Test]
    public function scriptAndStyleContentsAreRemoved(): void
    {
        self::assertSame(
            "Hallo\nWelt",
            $this->normalizer->description('<p>Hallo</p><script>alert(1)</script><STYLE type="text/css">p {}</STYLE>Welt'),
        );
        self::assertSame('ab', $this->normalizer->text("a<script>\nalert(1)\n</script>b", 100));
    }

    #[Test]
    #[DataProvider('amounts')]
    public function amountsAreParsed(mixed $input, int|float|null $expected): void
    {
        self::assertSame($expected, $this->normalizer->amount($input));
    }

    public static function amounts(): iterable
    {
        yield [90000, 90000];
        yield ["90'000", 90000];
        yield ['90 000', 90000];
        yield ['90,000', 90000];
        yield ['90.000', 90000];
        yield ['85k', 85000];
        yield ['1.5k', 1500];
        yield ['CHF 7\'500', 7500];
        yield ['32.50', 32.5];
        yield ['-5', null];
        yield ['auf Anfrage', null];
    }

    #[Test]
    public function salaryAndWorkLoadAreCleaned(): void
    {
        self::assertSame(
            ['min' => 90000, 'max' => 110000, 'currency' => 'CHF', 'interval' => 'yearly'],
            $this->normalizer->salary(['min' => '110k', 'max' => "90'000", 'currency' => 'chf', 'interval' => 'Yearly']),
        );
        self::assertNull($this->normalizer->salary(['currency' => 'CHF']));
        self::assertSame(['minPercentage' => 60, 'maxPercentage' => 80], $this->normalizer->workLoad(['minPercentage' => 80, 'maxPercentage' => '60']));
        self::assertSame(['maxPercentage' => 100], $this->normalizer->workLoad(['minPercentage' => 120, 'maxPercentage' => 100]));
    }

    #[Test]
    public function tagsAreUniqueShortAndLimited(): void
    {
        $tags = $this->normalizer->tags('Java, java, Spring Boot, ' . str_repeat('x', 29) . ', ' . implode(',', range(1, 30)));
        self::assertSame(['Java', 'Spring Boot'], array_slice($tags, 0, 2));
        self::assertCount(Spec::MAX_TAGS, $tags);
    }

    #[Test]
    public function aliasesMapToEnums(): void
    {
        self::assertSame('apprenticeship', $this->normalizer->enum('Lehrstelle', Spec::JOB_TYPES, ['lehrstelle' => 'apprenticeship']));
        $job = $this->normalizer->job('1', $this->validJob(['jobType' => 'full-time', 'workType' => 'onsite']), $this->context);
        self::assertSame('permanent', $job['jobType']);
        self::assertSame('on-site', $job['workType']);
    }

    #[Test]
    #[DataProvider('dates')]
    public function datesAreParsed(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer->date($input));
    }

    public static function dates(): iterable
    {
        yield ['2026-10-01', '2026-10-01'];
        yield ['31.12.2026', '2026-12-31'];
        yield ['2026-02-30', null];
        yield ['0000-00-00', null];
        yield [1791158400, '2026-10-05'];
        yield [new \DateTimeImmutable('2026-10-01 23:00'), '2026-10-01'];
        yield ['', null];
        yield [null, null];
    }

    #[Test]
    public function countriesAreValidated(): void
    {
        self::assertSame('CH', $this->normalizer->country('ch'));
        self::assertNull($this->normalizer->country('Schweiz'));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function unknownCountryCodesAreRejected(): void
    {
        self::assertNull($this->normalizer->country('XX'));
    }

    #[Test]
    public function employerNeedsName(): void
    {
        $this->expectException(InvalidEmployer::class);
        $this->normalizer->employer(['name' => ''], $this->context);
    }

    #[Test]
    public function employerLocationMayBeEmpty(): void
    {
        $employer = $this->normalizer->employer(['name' => 'Acme', 'url' => 'not a url'], new Context(new \DateTimeImmutable()));
        self::assertSame(['name' => 'Acme', 'location' => []], $employer);
    }
}
