<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Feed;

/**
 * JSON encoding with the pitfalls of PHP arrays handled: the schema forbids
 * "[]" where an object is expected (employer.location, job locations) and
 * requires "[]" for an empty jobs list.
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(array $document, bool $pretty = false): string
    {
        return json_encode(self::prepare($document), self::FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    private static function prepare(array $document): array
    {
        $document['employer']['location'] = (object)($document['employer']['location'] ?? []);
        $document['jobs'] = array_values(array_map(static function (array $job): array {
            $job['locations'] = array_map(static fn(array $l): object => (object)$l, array_values($job['locations'] ?? []));
            foreach (['workLoad', 'salary'] as $key) {
                if (isset($job[$key])) {
                    $job[$key] = (object)$job[$key];
                }
            }
            return $job;
        }, $document['jobs'] ?? []));

        return $document;
    }
}
