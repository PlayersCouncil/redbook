<?php

namespace App\Command;

use App\Services\Gemp\FormatsUpdater;
use App\Services\Gemp\GempUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:formats:refresh', description: "Copy Gemp's formats (run hourly by the app container's crontab)")]
class RefreshFormatsCommand extends Command
{
    public function __construct(private readonly FormatsUpdater $updater)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $counts = $this->updater->refresh();
        } catch (GempUnavailableException $e) {
            $io->error('Gemp unavailable, formats left as they were: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Formats: %d new, %d updated, %d retired', $counts['created'], $counts['updated'], $counts['retired']));
        return Command::SUCCESS;
    }
}
