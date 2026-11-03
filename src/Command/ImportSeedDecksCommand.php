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
            ->addArgument('batch', InputArgument::REQUIRED, 'The batch JSON file, or - to read it from standard input')
            ->addOption('no-validate', null, InputOption::VALUE_NONE, "Don't ask Gemp to validate each deck")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the batch without saving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('batch');
        $json = $path === '-' ? stream_get_contents(STDIN) : (is_file($path) ? file_get_contents($path) : null);
        $batch = is_string($json) ? json_decode($json, true) : null;
        unset($json);
        // a decoded batch takes about five times its file size, and stays in memory for the whole import
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes($limit) < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }
        if (!is_array($batch)) {
            $io->error("$path isn't a JSON file");
            return Command::FAILURE;
        }
        try {
            $bar = $io->createProgressBar(count($batch['decks'] ?? []));
            $report = $this->importer->import($batch, !$input->getOption('no-validate'), (bool) $input->getOption('dry-run'),
                function (int $done) use ($bar, $output) {
                    $bar->setProgress($done);
                    if ($output->isVerbose()) {
                        $output->write(sprintf(' %d MB (peak %d MB)', memory_get_usage() >> 20, memory_get_peak_usage() >> 20));
                    }
                });
            $bar->finish();
            $io->newLine(2);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        foreach ($report['problems'] as $problem) {
            $io->warning($problem);
        }
        $io->success(sprintf(
            '%s%d decklists created, %d updated, %d skipped, %d left alone (already verified); %d new historic accounts; %d formats guessed; %d flagged for review',
            $input->getOption('dry-run') ? '(dry run) ' : '',
            $report['created'], $report['updated'], $report['skipped'], $report['kept'], $report['accounts'], $report['guessed'], $report['flagged']
        ));
        return Command::SUCCESS;
    }

    private function bytes(string $limit): int
    {
        $value = (int) $limit;
        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
