<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Source;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SourceRegistry
{
    /** @var array<string, SourceInterface> */
    private array $sources = [];

    /**
     * @param iterable<SourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('ojobpub.source')]
        iterable $sources,
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->id()] = $source;
        }
    }

    /**
     * The configured source, or the native one if it is unknown or unavailable.
     */
    public function get(string $id): SourceInterface
    {
        $source = $this->sources[$id] ?? null;
        if ($source !== null && $source->isAvailable()) {
            return $source;
        }

        return $this->sources[NativeSource::ID];
    }

    /**
     * @return array<string, SourceInterface>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * @return list<string>
     */
    public function watchedTables(): array
    {
        $tables = [];
        foreach ($this->sources as $source) {
            if ($source->isAvailable()) {
                array_push($tables, ...$source->tables());
            }
        }

        return array_values(array_unique($tables));
    }
}
