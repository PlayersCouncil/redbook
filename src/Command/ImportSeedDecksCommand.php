<?php

namespace App\Command;

use App\Services\SeedDeckImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import:seed-decks', description: 'Import a seed-deck batch from the archive scrapes (phase3/06)')]
class ImportSeedDecksCommand extends Command
{
    public function __construct(private readonly SeedDeckImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'The batch JSON file')
            ->addOption('no-validate', null, InputOption::VALUE_NONE, "Don't ask Gemp to validate each deck")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the batch without saving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('batch');
        $batch = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($batch)) {
            $io->error("$path isn't a JSON file");
            return Command::FAILURE;
        }
        try {
            $report = $this->importer->import($batch, !$input->getOption('no-validate'), (bool) $input->getOption('dry-run'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        foreach ($report['problems'] as $problem) {
            $io->warning($problem);
        }
        $io->success(sprintf('%s%d decklists created, %d updated, %d skipped',
            $input->getOption('dry-run') ? '(dry run) ' : '', $report['created'], $report['updated'], $report['skipped']));
        return Command::SUCCESS;
    }
}
