<?php

namespace App\Services;

use App\Classes\DeckRow;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Entity\UserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * My Decks (Ketura, 2026-11-07): the user's decks, and the decklists they favourited, found
 * with the decklists page's filters and a few of its own. Someone's decks are at most a few
 * hundred, so they're all loaded and filtered here rather than in the database.
 */
class MyDecks
{
    public const SHOWS = ['private', 'hidden', 'published'];
    public const DEFAULT_SHOWS = ['private', 'hidden', 'published'];
    public const SORTS = ['new', 'old', 'name'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublishedDeckManager $decklists,
    ) {
    }

    /**
     * The decklists page's filters (PublishedDeckManager::getFilters), less those that
     * don't apply here; show, which decks to include, left out when it's the default; and
     * tab, favorites for the favourites tab (Ketura, 2026-11-07)
     */
    public function getFilters(Request $request): array
    {
        $filters = $this->decklists->getFilters();
        unset($filters['event'], $filters['played'], $filters['legal'], $filters['mine'], $filters['favorites']);
        if (!in_array($filters['sort'] ?? 'new', self::SORTS, true)) {
            unset($filters['sort']);
        }
        $show = array_values(array_intersect(self::SHOWS, (array) ($request->query->all()['show'] ?? [])));
        sort($show);
        $default = self::DEFAULT_SHOWS;
        sort($default);
        if ($show && $show !== $default) {
            $filters['show'] = $show;
        }
        if ($request->query->get('tab') === 'favorites') {
            $filters['tab'] = 'favorites';
        }
        return $filters;
    }

    /** @return DeckRow[] every deck of the user's, whatever the filters */
    public function deckRows(UserInterface $user): array
    {
        $decks = $this->em->createQuery('SELECT d, s, c, cu FROM ' . Deck::class . ' d LEFT JOIN d.slots s LEFT JOIN s.card c LEFT JOIN c.culture cu WHERE d.user = :user AND d.dateDeleted IS NULL')
            ->setParameter('user', $user)
            ->getResult();
        if ($decks) {
            // and what they were published as, with the thumbnails
            $this->em->createQuery('SELECT d, ch, tc FROM ' . Deck::class . ' d LEFT JOIN d.children ch LEFT JOIN ch.thumbnailCard tc WHERE d IN (:decks)')
                ->setParameter('decks', $decks)
                ->getResult();
        }
        return array_map(fn (Deck $deck) => DeckRow::fromDeck($deck), $decks);
    }

    /** @return DeckRow[] the public decklists the user favourited */
    public function favoriteRows(UserInterface $user): array
    {
        $decklists = $this->em->createQuery('SELECT d, u, tc FROM ' . PublishedDeck::class . ' d JOIN d.user u LEFT JOIN d.thumbnailCard tc WHERE :fan MEMBER OF d.favorites AND d.withdrawn = false')
            ->setParameter('fan', $user)
            ->getResult();
        if ($decklists) {
            foreach (['d.slots s LEFT JOIN s.card c' => 's, c', 'd.cultures dc LEFT JOIN dc.culture cu' => 'dc, cu', 'd.source src' => 'src'] as $join => $select) {
                $this->em->createQuery('SELECT d, ' . $select . ' FROM ' . PublishedDeck::class . ' d LEFT JOIN ' . $join . ' WHERE d IN (:decks)')
                    ->setParameter('decks', $decklists)
                    ->getResult();
            }
        }
        return array_map(fn (PublishedDeck $decklist) => DeckRow::fromFavorite($decklist), $decklists);
    }

    /**
     * The rows the filters keep, sorted
     * @param DeckRow[] $rows
     * @return DeckRow[]
     */
    public function filter(array $rows, array $filters): array
    {
        // the favourites tab shows only favourites; the decks tab, the decks included
        $favorites = ($filters['tab'] ?? 'decks') === 'favorites';
        $show = $filters['show'] ?? self::DEFAULT_SHOWS;
        $like = fn (string $needle, array $haystacks) => (bool) array_filter($haystacks, fn ($h) => mb_stripos((string) $h, $needle) !== false);
        $cardKeys = [];
        if (!empty($filters['cards'])) {
            foreach ($this->em->getRepository(Card::class)->findBy(['code' => $filters['cards']]) as $card) {
                $cardKeys[DeckRow::cardKey($card)] = true;
            }
        }
        $from = !empty($filters['from']) ? new \DateTimeImmutable($filters['from']) : null;
        $to = !empty($filters['to']) ? (new \DateTimeImmutable($filters['to']))->modify('+1 day') : null;

        $rows = array_values(array_filter($rows, function (DeckRow $row) use ($filters, $favorites, $show, $like, $cardKeys, $from, $to) {
            $cultures = array_map(fn ($c) => $c['culture']->getCode(), $row->cultures);
            return ($favorites ? $row->visibility === 'favorite' : in_array($row->visibility, $show, true))
                && (empty($filters['name']) || $like($filters['name'], [$row->name]))
                && (empty($filters['author']) || $like($filters['author'], $row->authors))
                && (empty($filters['format']) || $row->format === $filters['format'])
                && !array_diff_key($cardKeys, $row->cardKeys)
                && !array_diff($filters['culture'] ?? [], $cultures)
                && !array_diff($filters['tags'] ?? [], $row->tags)
                && (empty($filters['kind']) || in_array($row->getKind(), $filters['kind'], true))
                && (!$from || $row->date >= $from)
                && (!$to || $row->date < $to);
        }));

        $sort = $filters['sort'] ?? 'new';
        usort($rows, match ($sort) {
            'old' => fn (DeckRow $a, DeckRow $b) => $a->date <=> $b->date,
            'name' => fn (DeckRow $a, DeckRow $b) => strcasecmp($a->name, $b->name) ?: $b->date <=> $a->date,
            default => fn (DeckRow $a, DeckRow $b) => $b->date <=> $a->date,
        });
        return $rows;
    }

    /**
     * The formats the rows target, with how many: [code => count], most used first
     * @param DeckRow[] $rows
     */
    public function countFormats(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if ($row->format !== '') {
                $counts[$row->format] = ($counts[$row->format] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }
}
