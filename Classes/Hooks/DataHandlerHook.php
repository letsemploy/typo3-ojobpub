<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Hooks;

use LetsEmploy\OJobPub\Service\FeedService;
use LetsEmploy\OJobPub\Source\SourceRegistry;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Flushes the cached feeds when a record of a watched table changes
 * (create, edit, hide, delete, move, localize, ...).
 *
 * Flushes all sites: cheap, and avoids mapping records to sites.
 * TODO: flush per site via the record's pid once multi-site setups need it.
 */
final class DataHandlerHook
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly FeedService $feedService,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->flushIfWatched(array_keys($dataHandler->datamap));
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->flushIfWatched(array_keys($dataHandler->cmdmap));
    }

    /**
     * @param list<string> $tables
     */
    private function flushIfWatched(array $tables): void
    {
        if (array_intersect($tables, $this->sources->watchedTables()) !== []) {
            $this->feedService->flush();
        }
    }
}
