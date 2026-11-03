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

/**
 * Imports seed-deck batches (phase3/06). With no argument it reads every *.json in
 * var/seed-decks, which docker-compose mounts from docker/data/seed-decks on the
 * host (Ketura, 2026-11-02: drop the batch files in a folder, run one command).
 * Re-running is safe: decks are keyed by (source, sourceId) and verified ones are
 * left alone.
 */
#[AsCommand(name: 'app:import:seed-decks', description: 'Import seed-deck batches from the archive scrapes (phase3/06)')]
class ImportSeedDecksCommand extends Command
{
    public const DEFAULT_FOLDER = 'var/seed-decks';

    public function __construct(
        private readonly SeedDeckImporter $importer,
        private readonly string $kernelProjectDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::OPTIONAL, 'A batch JSON file, a folder of them, or - to read one from standard input (default: every *.json in ' . self::DEFAULT_FOLDER . ')')
            ->addOption('no-validate', null, InputOption::VALUE_NONE, "Don't ask Gemp to validate each deck")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the batches without saving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // a decoded batch takes about five times its file size, and stays in memory while it imports
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes($limit) < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }

        $path = $input->getArgument('batch') ?? $this->kernelProjectDirectory . '/' . self::DEFAULT_FOLDER;
        if ($path === '-') {
            return $this->importOne($io, $input, $output, 'standard input', stream_get_contents(STDIN)) ? Command::SUCCESS : Command::FAILURE;
        }
        if (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*.json') ?: [];
            sort($files);
            if (!$files) {
                $io->error("No .json files in $path");
                return Command::FAILURE;
            }
            $io->title(sprintf('%d batch files in %s', count($files), $path));
        } elseif (is_file($path)) {
            $files = [$path];
        } else {
            $io->error("$path isn't a file or a folder");
            return Command::FAILURE;
        }

        $failed = [];
        foreach ($files as $file) {
            if (!$this->importOne($io, $input, $output, basename($file), file_get_contents($file))) {
                $failed[] = basename($file);
            }
        }
        if (count($files) > 1) {
            $failed
                ? $io->error(sprintf('%d of %d files failed: %s', count($failed), count($files), implode(', ', $failed)))
                : $io->success(sprintf('All %d files imported.', count($files)));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function importOne(SymfonyStyle $io, InputInterface $input, OutputInterface $output, string $name, string|false $json): bool
    {
        $io->section($name);
        $batch = is_string($json) ? json_decode($json, true) : null;
        unset($json);
        if (!is_array($batch)) {
            $io->error("$name isn't a JSON file");
            return false;
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
            $io->error("$name: " . $e->getMessage());
            return false;
        }
        unset($batch);
        foreach ($report['problems'] as $problem) {
            $io->warning($problem);
        }
        $io->success(sprintf(
            '%s%s: %d decklists created, %d updated, %d skipped, %d left alone (already verified); %d new historic accounts; %d formats guessed; %d flagged for review',
            $input->getOption('dry-run') ? '(dry run) ' : '', $name,
            $report['created'], $report['updated'], $report['skipped'], $report['kept'], $report['accounts'], $report['guessed'], $report['flagged']
        ));
        return true;
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
