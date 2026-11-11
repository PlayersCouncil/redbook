<?php

namespace App\Command;

use App\Classes\DeckContent;
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
        $db = $this->em->getConnection();
        // compared as plain rows, the columns that matter and nothing else: loading the
        // decklists themselves brings their archive sources along, too much for thousands
        // (Ketura, 2026-11-08). Only a decklist that needs it is loaded, to be updated.
        $ids = $db->fetchFirstColumn('SELECT id FROM published_deck WHERE review_status IS NOT NULL AND parent_deck_id IS NOT NULL ORDER BY id');
        $columns = 'name, description_md, starting_fellowship, starting_bid, starting_notes, annotations';
        $count = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));
            $decklists = $db->fetchAllAssociativeIndexed("SELECT id, parent_deck_id, $columns FROM published_deck WHERE id IN ($list)");
            $deckIds = implode(',', array_map('intval', array_column($decklists, 'parent_deck_id')));
            $decks = $db->fetchAllAssociativeIndexed("SELECT id, $columns FROM deck WHERE id IN ($deckIds)");
            $listCards = $this->contents('published_deck_slot', 'published_deck_id', $list);
            $deckCards = $this->contents('deck_slot', 'deck_id', $deckIds);

            $update = [];
            foreach ($decklists as $id => $row) {
                $deck = $decks[$row['parent_deck_id']] ?? null;
                if ($deck === null) {
                    continue;
                }
                $differs = $this->differences($row, $deck, $listCards[$id] ?? [], $deckCards[$row['parent_deck_id']] ?? []);
                if ($differs) {
                    $count++;
                    $update[] = $id;
                    $output->writeln(sprintf('#%d %s: %s', $id, trim((string) $row['name']), implode(', ', $differs)));
                }
            }
            if (!$dry) {
                foreach ($update as $id) {
                    $decklist = $this->em->find(PublishedDeck::class, $id);
                    $this->factory->refreshFromDeck($decklist, $decklist->getParent());
                    $this->em->flush();
                    $this->em->clear();
                }
            }
            if ($output->isVerbose()) {
                $output->writeln(sprintf('... %d checked, %.0f MB', count($chunk), memory_get_usage(true) / 1048576));
            }
        }
        $output->writeln(sprintf('%d archive decklist(s) %s.', $count, $dry ? 'would be updated' : 'updated'));
        return Command::SUCCESS;
    }

    /** @return array<int, array<string, int>> each deck's cards, by content key (rb: for the Ring-bearer) */
    private function contents(string $table, string $column, string $ids): array
    {
        $contents = [];
        if ($ids === '') {
            return $contents;
        }
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT s.$column AS owner, c.code, s.role, s.quantity FROM $table s JOIN card c ON c.id = s.card_id WHERE s.$column IN ($ids)"
        );
        foreach ($rows as $row) {
            $contents[(int) $row['owner']][DeckContent::key((string) $row['code'], (string) $row['role'])] = (int) $row['quantity'];
        }
        foreach ($contents as &$content) {
            ksort($content);
        }
        return $contents;
    }

    /** @return string[] what the decklist has differently from its deck */
    private function differences(array $decklist, array $deck, array $listCards, array $deckCards): array
    {
        $json = fn ($value) => $value === null || $value === '' ? [] : (json_decode((string) $value, true) ?? []);
        $pairs = [
            'cards' => [$listCards, $deckCards],
            'name' => [trim((string) $decklist['name']), trim((string) $deck['name']) ?: 'Untitled'],
            'description' => [(string) $decklist['description_md'], (string) $deck['description_md']],
            'starting' => [\App\Classes\StartingGroups::normalize($json($decklist['starting_fellowship'])), \App\Classes\StartingGroups::normalize($json($deck['starting_fellowship']))],
            'bid' => [(string) $decklist['starting_bid'], (string) $deck['starting_bid']],
            'starting notes' => [(string) $decklist['starting_notes'], (string) $deck['starting_notes']],
            'annotations' => [$json($decklist['annotations']), $json($deck['annotations'])],
        ];
        return array_keys(array_filter($pairs, fn ($pair) => $pair[0] != $pair[1]));
    }
}
