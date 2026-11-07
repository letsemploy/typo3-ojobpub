<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPubDev\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\Tca\TcaMigration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Like "Check TCA migrations" in the Upgrade module, for the extension's own TCA
 * files (without Overrides, which only make sense on top of the full TCA).
 * Fails if TYPO3 would have to migrate anything.
 */
#[AsCommand(name: 'ojobpub-dev:check-tca', description: 'Fail if the oJobPub TCA needs migrations in this TYPO3 version.')]
final class CheckTcaCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tca = [];
        foreach (glob(ExtensionManagementUtility::extPath('ojobpub') . 'Configuration/TCA/*.php') as $file) {
            $tca[basename($file, '.php')] = require $file;
        }

        $migration = GeneralUtility::makeInstance(TcaMigration::class);
        $result = $migration->migrate($tca);
        // v13 returns the migrated array and keeps the messages, v14 returns a TcaProcessingResult.
        $messages = is_array($result) ? $migration->getMessages() : $result->getMessages();

        if ($messages !== []) {
            $io->error('TCA migrations needed for ' . implode(', ', array_keys($tca)));
            $io->listing($messages);
            return Command::FAILURE;
        }
        $io->success('No TCA migrations needed for ' . implode(', ', array_keys($tca)));
        return Command::SUCCESS;
    }
}
