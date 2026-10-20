<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Command;

use LetsEmploy\OJobPub\Service\FeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * vendor/bin/typo3 ojobpub:feed [site] [--issues] [--no-cache]
 *
 * Prints the feed of a site as consumers get it, or the jobs that were left out and why.
 */
#[AsCommand(name: 'ojobpub:feed', description: 'Print the oJobPub feed of a site, or the jobs left out of it.')]
final class FeedCommand extends Command
{
    public function __construct(
        private readonly FeedService $feedService,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier; defaults to the first site')
            ->addOption('issues', 'i', InputOption::VALUE_NONE, 'List jobs left out of the feed instead of printing it')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Rebuild instead of reading the cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('site');
        $sites = $this->siteFinder->getAllSites();
        $site = $identifier !== null ? ($sites[$identifier] ?? null) : (reset($sites) ?: null);
        if ($site === null) {
            $io->error('Site not found. Available: ' . implode(', ', array_keys($sites)));
            return Command::FAILURE;
        }
        if (!$this->feedService->isEnabled($site)) {
            $io->warning(sprintf('The feed is disabled for site "%s" (setting ojobpub.enabled).', $site->getIdentifier()));
            return Command::FAILURE;
        }

        $feed = $this->feedService->get($site, !$input->getOption('no-cache'));
        if ($feed === null) {
            $io->error('The feed cannot be built. Is ojobpub.employer.name set?');
            return Command::FAILURE;
        }

        if ($input->getOption('issues')) {
            $io->text(sprintf('Source: %s, %d jobs published, %d left out.', $feed->sourceId, $feed->jobCount, count($feed->issues)));
            if ($feed->issues !== []) {
                $io->table(['Job', 'Title', 'Reason'], array_map(static fn($i) => [$i->jobKey, $i->title, $i->reason], $feed->issues));
            }
            return Command::SUCCESS;
        }

        $output->writeln(json_encode(json_decode($feed->json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
