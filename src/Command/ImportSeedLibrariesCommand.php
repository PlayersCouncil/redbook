<?php

namespace App\Command;

use App\Services\SeedLibraryImporter;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports seeded libraries (Ketura, 2026-11-09): the PC's official groupings, from manifests
 * (docs/seed-libraries.md). With no argument it reads every *.json in var/seed-libraries,
 * which docker-compose mounts from docker/data/seed-libraries, as the seed decks are. Safe to
 * re-run: each library is keyed by its manifest's source and its id, and follows the manifest.
 * Run it after the decks it names are in (app:import:seed-decks).
 */
#[AsCommand(name: 'app:import:seed-libraries', description: 'Import seeded (official) libraries from manifests')]
class ImportSeedLibrariesCommand extends Command
{
    public const DEFAULT_FOLDER = 'var/seed-libraries';

    public function __construct(
        private readonly SeedLibraryImporter $importer,
        private readonly string $kernelProjectDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manifest', InputArgument::OPTIONAL, 'A manifest JSON file, a folder of them, or - to read one from standard input (default: every *.json in ' . self::DEFAULT_FOLDER . ')')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the manifests and the decks they name without saving anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('manifest') ?? $this->kernelProjectDirectory . '/' . self::DEFAULT_FOLDER;
        $files = $path === '-' ? ['php://stdin'] : (is_dir($path) ? (glob(rtrim($path, '/') . '/*.json') ?: []) : (is_file($path) ? [$path] : []));
        if (!$files) {
            $io->error("No manifests at $path");
            return Command::FAILURE;
        }
        sort($files);
        $failed = 0;
        foreach ($files as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            if (!is_array($manifest)) {
                $io->error(basename($file) . ': not valid JSON');
                $failed++;
                continue;
            }
            try {
                $report = $this->importer->import($manifest, (bool) $input->getOption('dry-run'));
            } catch (InvalidArgumentException $e) {
                $io->error(basename($file) . ': ' . $e->getMessage());
                $failed++;
                continue;
            }
            $io->writeln(sprintf('<info>%s</info>: %d created, %d updated, %d decks%s', basename($file), $report['created'], $report['updated'], $report['decks'],
                $input->getOption('dry-run') ? ' (dry run)' : ''));
            if ($report['missing']) {
                $io->warning(sprintf("%d decks not found (left out):\n%s", count($report['missing']), implode("\n", $report['missing'])));
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
