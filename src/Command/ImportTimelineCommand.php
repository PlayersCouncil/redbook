<?php

namespace App\Command;

use App\Services\FormatTimeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Imports a format-timeline.json for the Formats page's Timeline tab (Ketura, 2026-11-11) */
#[AsCommand(name: 'app:import:timeline', description: 'Import the format timeline (format-timeline.json)')]
class ImportTimelineCommand extends Command
{
    public function __construct(private readonly FormatTimeline $timeline)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The format-timeline.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $done = $this->timeline->import((string) $input->getArgument('path'));
        $io->success(sprintf('Format timeline %s (generated %s): %d events, %d formats, %d micro-formats',
            $done['schema'], $done['generatedAt'] ?? '?', $done['events'], $done['formats'], $done['microformats']));
        return Command::SUCCESS;
    }
}
