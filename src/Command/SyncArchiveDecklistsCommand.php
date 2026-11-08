<?php

namespace App\Command;

use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Services\PublishedDeckFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings archive decklists up to their decks (Ketura, 2026-11-08). Until then a historian's
 * plain Save changed only the deck, so its decklist kept the old cards, notes and annotations
 * unless they used Save & verify; since then every historian save carries over. This catches
 * up the decklists saved before: those whose deck differs from them.
 */
#[AsCommand(name: 'app:archive:sync', description: 'Update archive decklists from the decks historians edited')]
class SyncArchiveDecklistsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublishedDeckFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change, changing nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        $decklists = $this->em->createQuery('SELECT d, p FROM ' . PublishedDeck::class . ' d JOIN d.parent p WHERE d.reviewStatus IS NOT NULL')->getResult();
        $count = 0;
        foreach ($decklists as $decklist) {
            $deck = $decklist->getParent();
            $differs = $this->differences($decklist, $deck);
            if (!$differs) {
                continue;
            }
            $count++;
            $output->writeln(sprintf('#%d %s: %s', $decklist->getId(), $decklist->getName(), implode(', ', $differs)));
            if (!$dry) {
                $this->factory->refreshFromDeck($decklist, $deck);
            }
        }
        if (!$dry) {
            $this->em->flush();
        }
        $output->writeln(sprintf('%d archive decklist(s) %s.', $count, $dry ? 'would be updated' : 'updated'));
        return Command::SUCCESS;
    }

    /** @return string[] what the decklist has differently from its deck */
    private function differences(PublishedDeck $decklist, Deck $deck): array
    {
        $pairs = [
            'cards' => [self::sorted($decklist->getSlots()->getContent()), self::sorted($deck->getSlots()->getContent())],
            'name' => [trim((string) $decklist->getName()), trim((string) $deck->getName()) ?: 'Untitled'],
            'description' => [(string) $decklist->getDescriptionMd(), (string) $deck->getDescriptionMd()],
            'starting' => [array_values($decklist->getStarting()), array_values($deck->getStarting())],
            'bid' => [(string) $decklist->getStartingBid(), (string) $deck->getStartingBid()],
            'starting notes' => [(string) $decklist->getStartingNotes(), (string) $deck->getStartingNotes()],
            'annotations' => [$decklist->getAnnotations(), $deck->getAnnotations()],
        ];
        return array_keys(array_filter($pairs, fn ($pair) => $pair[0] != $pair[1]));
    }

    private static function sorted(array $content): array
    {
        ksort($content);
        return $content;
    }
}
