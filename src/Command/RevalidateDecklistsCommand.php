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
 */
#[AsCommand(name: 'app:decklists:revalidate', description: 'Ask Gemp to validate decklists that have no validation yet')]
class RevalidateDecklistsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DeckValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many decklists', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $decklists = $this->em->createQuery(
            'SELECT d FROM ' . PublishedDeck::class . " d WHERE d.completeness = :unvalidated AND d.targetFormat <> '' ORDER BY d.id"
        )->setParameter('unvalidated', CommonDeckInterface::COMPLETENESS_UNVALIDATED)
            ->setMaxResults((int) $input->getOption('limit'))->getResult();
        $done = 0;
        foreach ($decklists as $decklist) {
            if ($this->validator->validateDeck($decklist) === null) {
                $io->warning('Gemp unavailable; stopping');
                break;
            }
            $done++;
        }
        $this->em->flush();
        $io->success(sprintf('%d of %d decklists validated', $done, count($decklists)));
        return Command::SUCCESS;
    }
}
