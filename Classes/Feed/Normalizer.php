<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * Turns raw data from a source into schema-valid oJobPub structures.
 *
 * Sources (adapters) stay sloppy, this class stays strict: every schema rule
 * lives here. A job that cannot be made valid is returned as an Issue and left
 * out of the feed; it never invalidates the whole document.
 *
 * No TYPO3 dependency: keep it that way so it stays unit-testable.
 */
final class Normalizer
{
    private const JOB_TYPE_ALIASES = [
        'full-time' => 'permanent', 'fulltime' => 'permanent', 'full_time' => 'permanent',
        'part-time' => 'permanent', 'parttime' => 'permanent', 'part_time' => 'permanent',
        'festanstellung' => 'permanent', 'unbefristet' => 'permanent',
        'contractor' => 'contract', 'befristet' => 'temporary', 'temp' => 'temporary',
        'intern' => 'internship', 'praktikum' => 'internship',
        'apprentice' => 'apprenticeship', 'lehrstelle' => 'apprenticeship', 'lehre' => 'apprenticeship',
        'volunteering' => 'volunteer', 'ehrenamt' => 'volunteer', 'freiwillig' => 'volunteer',
        'freelancer' => 'freelance',
    ];

    private const WORK_TYPE_ALIASES = [
        'onsite' => 'on-site', 'on_site' => 'on-site', 'office' => 'on-site', 'vor-ort' => 'on-site',
        'homeoffice' => 'remote', 'home-office' => 'remote', 'telework' => 'remote',
    ];

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidEmployer when the employer cannot be made valid (the feed cannot exist without one)
     */
    public function employer(array $raw, Context $context): array
    {
        $name = $this->text($raw['name'] ?? '', Spec::MAX_NAME);
        if ($name === '') {
            throw new InvalidEmployer('Employer name is missing.');
        }

        $employer = [
            'name' => $name,
            'location' => $this->location(is_array($raw['location'] ?? null) ? $raw['location'] : [], $context) ?? [],
        ];

        $industry = $this->text($raw['industry'] ?? '', Spec::MAX_INDUSTRY);
        if ($industry !== '') {
            $employer['industry'] = $industry;
        }
        $url = $this->url($raw['url'] ?? '');
        if ($url !== null) {
            $employer['url'] = $url;
        }

        return $employer;
    }

    /**
     * @return array<string, mixed>|Issue
     */
    public function job(string $key, array $raw, Context $context): array|Issue
    {
        $title = $this->text($raw['title'] ?? '', Spec::MAX_TITLE);
        $fail = static fn(string $reason): Issue => new Issue($key, $title, $reason);

        if ($title === '') {
            return $fail('Title is missing.');
        }

        $applyBefore = $this->date($raw['applyBefore'] ?? null);
        if ($applyBefore !== null && $applyBefore < $context->today->format('Y-m-d')) {
            return $fail(sprintf('Application deadline %s has passed.', $applyBefore));
        }

        $language = $this->language($raw['language'] ?? '') ?? $this->language($context->defaultLanguage);
        if ($language === null) {
            return $fail('Language is missing or not an ISO 639-1 code.');
        }

        $publishedAt = $this->date($raw['publishedAt'] ?? null);
        if ($publishedAt === null) {
            return $fail('Publication date is missing.');
        }

        $jobType = $this->enum($raw['jobType'] ?? '', Spec::JOB_TYPES, self::JOB_TYPE_ALIASES);
        if ($jobType === null) {
            return $fail('Job type is missing or unknown.');
        }

        $url = $this->url($raw['url'] ?? '');
        if ($url === null) {
            return $fail('Job URL is missing or not an absolute http(s) URL.');
        }

        $locations = [];
        foreach (is_array($raw['locations'] ?? null) ? $raw['locations'] : [] as $rawLocation) {
            $location = is_array($rawLocation) ? $this->location($rawLocation, $context) : null;
            if ($location !== null && !in_array($location, $locations, true)) {
                $locations[] = $location;
            }
        }
        if ($locations === [] && ($fallback = $this->location($context->fallbackLocation, $context)) !== null) {
            $locations[] = $fallback;
        }
        if ($locations === []) {
            return $fail('Location is missing and the employer has no location to fall back to.');
        }

        $job = [
            'title' => $title,
            'language' => $language,
            'publishedAt' => $publishedAt,
            'jobType' => $jobType,
            'locations' => $locations,
            'url' => $url,
            'description' => $this->description($raw['description'] ?? ''),
            'category' => $this->text($raw['category'] ?? '', Spec::MAX_CATEGORY),
            'referenceId' => $this->text($raw['referenceId'] ?? '', Spec::MAX_REFERENCE_ID),
            'applyBefore' => $applyBefore,
            'startDate' => $this->date($raw['startDate'] ?? null),
            'endDate' => $this->date($raw['endDate'] ?? null),
            'workType' => $this->enum($raw['workType'] ?? '', Spec::WORK_TYPES, self::WORK_TYPE_ALIASES),
            'experienceLevel' => $this->enum($raw['experienceLevel'] ?? '', Spec::EXPERIENCE_LEVELS),
            'workLoad' => $this->workLoad($raw['workLoad'] ?? null),
            'salary' => $this->salary($raw['salary'] ?? null),
            'tags' => $this->tags($raw['tags'] ?? []),
        ];

        // Drop empty optional values; required ones are guaranteed non-empty above.
        return array_filter($job, static fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Plain text: no tags, entities decoded, whitespace collapsed, cut to $max characters.
     */
    public function text(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }

    /**
     * Like text(), but keeps paragraph breaks, removes javascript: URLs and
     * truncates at a word boundary with an ellipsis.
     */
    public function description(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $html = (string)$value;
        // Keep block boundaries as line breaks before stripping tags.
        $html = (string)preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/javascript\s*:/iu', '', $text);
        $text = (string)preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = trim((string)preg_replace('/\s*\n\s*/u', "\n", $text));

        if (mb_strlen($text) <= Spec::MAX_DESCRIPTION) {
            return $text;
        }
        $cut = mb_substr($text, 0, Spec::MAX_DESCRIPTION - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > Spec::MAX_DESCRIPTION * 0.8) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \n,.;:-") . '…';
    }

    /**
     * Accepts DateTimeInterface, a unix timestamp or anything strtotime() understands.
     */
    public function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) > 8)) {
            return (int)$value > 0 ? gmdate('Y-m-d', (int)$value) : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            // 1970-01-01 and earlier are placeholders some systems store for "no date".
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && $value > '1970-01-01' ? $value : null;
        }
        // Swiss/German notation 31.12.2026
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        $timestamp = strtotime($value);
        // Guard against placeholders like 1970-01-01 that some plugins store for "no date".
        return $timestamp !== false && $timestamp > 86400 ? gmdate('Y-m-d', $timestamp) : null;
    }

    /**
     * ISO 639-1 from "de", "DE", "de_CH", "de-CH", "de_CH.UTF-8".
     */
    public function language(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $code = strtolower(substr(trim($value), 0, 2));
        $rest = substr(trim($value), 2);

        return preg_match('/^[a-z]{2}$/', $code) && ($rest === '' || $rest[0] === '_' || $rest[0] === '-')
            ? $code
            : null;
    }

    public function country(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $code = strtoupper(trim($value));
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            // TODO: resolve country names ("Schweiz", "Switzerland") like the WordPress plugin's Feed\Country.
            return null;
        }
        if (class_exists(\Locale::class)) {
            // ICU returns the code itself for unknown regions.
            $name = \Locale::getDisplayRegion('und-' . $code, 'en');
            if ($name === '' || $name === $code) {
                return null;
            }
        }

        return $code;
    }

    /**
     * @return array{city?: string, country?: string}|null null when nothing usable is left
     */
    public function location(array $raw, Context $context): ?array
    {
        $location = [];
        $city = $this->text($raw['city'] ?? '', 255);
        if ($city !== '') {
            $location['city'] = $city;
        }
        $country = $this->country($raw['country'] ?? '') ?? ($city !== '' ? $this->country($context->defaultCountry) : null);
        if ($country !== null) {
            $location['country'] = $country;
        }

        return $location === [] ? null : $location;
    }

    /**
     * @param list<string> $allowed
     * @param array<string, string> $aliases
     */
    public function enum(mixed $value, array $allowed, array $aliases = []): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $value = $aliases[$value] ?? $value;

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @return array{minPercentage?: int|float, maxPercentage?: int|float}|null
     */
    public function workLoad(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $min = $this->number($raw['minPercentage'] ?? null);
        $max = $this->number($raw['maxPercentage'] ?? null);
        $min = $min !== null && $min >= 0 && $min <= 100 ? $min : null;
        $max = $max !== null && $max >= 0 && $max <= 100 ? $max : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        $workLoad = array_filter(['minPercentage' => $min, 'maxPercentage' => $max], static fn($v) => $v !== null);

        return $workLoad === [] ? null : $workLoad;
    }

    /**
     * @return array{min?: int|float, max?: int|float, currency?: string, interval?: string}|null
     */
    public function salary(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $min = $this->amount($raw['min'] ?? null);
        $max = $this->amount($raw['max'] ?? null);
        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        $currency = is_string($raw['currency'] ?? null) ? strtoupper(trim($raw['currency'])) : '';
        $salary = [
            'min' => $min,
            'max' => $max,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            'interval' => $this->enum($raw['interval'] ?? '', Spec::SALARY_INTERVALS),
        ];

        return array_filter($salary, static fn($v) => $v !== null);
    }

    /**
     * Parses amounts as editors type them: 90000, "90'000", "90 000", "90,000", "85k", "1.5k".
     */
    public function amount(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $value = (string)preg_replace("/[\\s'’`]|chf|eur|usd|fr\\.?/u", '', $value);
        $factor = 1;
        if (str_ends_with($value, 'k')) {
            $factor = 1000;
            $value = substr($value, 0, -1);
        }
        // "90,000" / "90.000" as thousands separator, "1,5" / "1.5" as decimal.
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $value)) {
            $value = str_replace([',', '.'], '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value) || (float)$value < 0) {
            return null;
        }

        return $this->number((float)$value * $factor);
    }

    /**
     * @return list<string>
     */
    public function tags(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,;\n]/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $tags = [];
        $seen = [];
        foreach ($raw as $tag) {
            $tag = $this->text($tag, 1000);
            // Too long tags are dropped, not truncated: a cut tag is a wrong tag.
            if ($tag === '' || mb_strlen($tag) > Spec::MAX_TAG_LENGTH) {
                continue;
            }
            $lower = mb_strtolower($tag);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $tags[] = $tag;
            if (count($tags) === Spec::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    public function url(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    private function number(mixed $value): int|float|null
    {
        if (is_string($value) && is_numeric(trim($value))) {
            $value = (float)$value;
        }
        if (!is_int($value) && !is_float($value) || !is_finite((float)$value)) {
            return null;
        }
        // Integers stay integers in JSON ("90000", not "90000.0").
        return (float)$value === floor((float)$value) && abs($value) < PHP_INT_MAX ? (int)$value : (float)$value;
    }
}
