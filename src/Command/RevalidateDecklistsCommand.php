<?php

namespace App\Command;

use App\Entity\CommonDeckInterface;
use App\Entity\PublishedDeck;
use App\Services\DeckValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Decklists published while Gemp was unreachable are "unvalidated" (decision
 * 0023); this asks Gemp again. Run from the crontab; stops at the first failure,
 * since Gemp is then still down.
 *
 * --all asks again about every decklist, checked or not (Ketura, 2026-11-09: for when the
 * answers on record are wrong, as when GEMP_URL pointed at a Gemp without the endpoints).
 * Saved in batches, so stopping partway keeps what was done.
 */
#[AsCommand(name: 'app:decklists:revalidate', description: 'Ask Gemp to validate decklists that have no validation yet (--all: every decklist)')]
class RevalidateDecklistsCommand extends Command
{
    private const BATCH = 100;

    public function __construct(private readonly EntityManagerInterface $em, private readonly DeckValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many decklists', 200);
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Every decklist, not only the unvalidated ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = (bool) $input->getOption('all');
        $limit = (int) $input->getOption('limit');
        $where = "d.targetFormat <> ''" . ($all ? '' : ' AND d.completeness = :unvalidated');
        $params = $all ? [] : ['unvalidated' => CommonDeckInterface::COMPLETENESS_UNVALIDATED];

        $total = min($limit, (int) $this->em->createQuery('SELECT COUNT(d.id) FROM ' . PublishedDeck::class . " d WHERE $where")
            ->setParameters($params)->getSingleScalarResult());
        $io->progressStart($total);
        $done = 0;
        $afterId = 0;
        $stopped = false;
        while (!$stopped && $done < $total) {
            // by id rather than by state: --all leaves a decklist's state as it found it, so the
            // next batch can't be found by what's left to do
            $batch = $this->em->createQuery('SELECT d FROM ' . PublishedDeck::class . " d WHERE $where AND d.id > :after ORDER BY d.id")
                ->setParameters($params + ['after' => $afterId])
                ->setMaxResults(min(self::BATCH, $total - $done))->getResult();
            if (!$batch) {
                break;
            }
            foreach ($batch as $decklist) {
                $afterId = $decklist->getId();
                if ($this->validator->validateDeck($decklist) === null) {
                    $stopped = true;
                    break;
                }
                $done++;
                $io->progressAdvance();
            }
            $this->em->flush();
            $this->em->clear();
        }
        if ($stopped) {
            $io->newLine(2); // not progressFinish: it would fill the bar
            $io->warning('Gemp unavailable; stopping');
        } else {
            $io->progressFinish();
        }
        $io->success(sprintf('%d of %d decklists validated', $done, $total));
        return Command::SUCCESS;
    }
}
