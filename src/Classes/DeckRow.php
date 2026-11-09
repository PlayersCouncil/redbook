<?php

namespace App\Classes;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\PublishedDeck;

/**
 * A row of My Decks (Ketura, 2026-11-07): one of the user's decks, or a decklist they
 * favourited, with what the page shows and filters on.
 */
final class DeckRow
{
    /** private, hidden, published (the user's decks) or favorite */
    public string $visibility;
    public string $name;
    public string $format;
    /** a deck's last change; a favourite's publication */
    public \DateTimeInterface $date;
    public int $nbFreePeoples = 0;
    public int $nbShadow = 0;
    /** @var array<array{culture: \App\Entity\Culture, count: int}> the draw deck's, most cards first */
    public array $cultures = [];
    /** @var string[] */
    public array $tags = [];
    /** @var string[] the names the author goes by, for the Author filter */
    public array $authors = [];
    /** @var array<string, true> the cards, by name and subtitle: any printing matches */
    public array $cardKeys = [];
    /** what Decklist/thumbnail.html.twig shows */
    public object $thumbnail;
    /** a published deck's newest public decklist */
    public ?PublishedDeck $published = null;
    /** who can see the deck as it stands: private or hidden (Ketura, 2026-11-08) */
    public string $current = 'private';
    /** a published deck still as it was published: no private revision since */
    public bool $sameAsPublished = false;

    private function __construct(public readonly ?Deck $deck, public readonly ?PublishedDeck $decklist)
    {
    }

    public static function cardKey(Card $card): string
    {
        return $card->getName() . '|' . ($card->getSubtitle() ?? '');
    }

    public static function fromDeck(Deck $deck): self
    {
        $row = new self($deck, null);
        foreach ($deck->getChildren() as $decklist) {
            if (!$decklist->isWithdrawn() && ($row->published === null || $decklist->getDateCreation() > $row->published->getDateCreation())) {
                $row->published = $decklist;
            }
        }
        $row->current = $deck->isShared() ? 'hidden' : 'private';
        $row->visibility = $row->published ? 'published' : $row->current;
        $row->sameAsPublished = $row->published !== null && (int) $deck->getMinorVersion() === 0
            && (int) $deck->getMajorVersion() === (int) $row->published->getVersion();
        $row->name = (string) $deck->getName();
        $row->format = $deck->getTargetFormat();
        $row->date = $deck->getDateUpdate() ?? $deck->getDateCreation();
        $row->tags = preg_split('/\s+/', (string) $deck->getTags(), -1, PREG_SPLIT_NO_EMPTY);
        $user = $deck->getUser();
        $row->authors = [$user->getUsername(), $user->getDisplayName()];

        $counts = [];
        foreach ($deck->getSlots() as $slot) {
            $row->cardKeys[self::cardKey($slot->getCard())] = true;
        }
        foreach ($deck->getSlots()->getDrawDeck() as $slot) {
            $card = $slot->getCard();
            $quantity = (int) $slot->getQuantity();
            if ($card->getSide() === Card::SIDE_FREE_PEOPLES) {
                $row->nbFreePeoples += $quantity;
            } elseif ($card->getSide() === Card::SIDE_SHADOW) {
                $row->nbShadow += $quantity;
            }
            if ($card->getSide() && $card->getCulture()) {
                $count = &$counts[$card->getSide()][$card->getCulture()->getCode()];
                $count ??= ['name' => $card->getCulture()->getName(), 'count' => 0];
                $count['count'] += $quantity;
                unset($count);
            }
        }
        $row->cultures = array_values($deck->getSlots()->getCultures());
        // a published deck shows its decklist's thumbnail; the others their cultures
        $row->thumbnail = $row->published ?? new DeckThumbnail(PublishedDeck::pickThumbnailCultures($counts));
        return $row;
    }

    public static function fromFavorite(PublishedDeck $decklist): self
    {
        $row = new self(null, $decklist);
        $row->visibility = 'favorite';
        $row->name = (string) $decklist->getName();
        $row->format = $decklist->getTargetFormat();
        $row->date = $decklist->getDateCreation();
        $row->nbFreePeoples = $decklist->getNbFreePeoples();
        $row->nbShadow = $decklist->getNbShadow();
        $row->tags = preg_split('/\s+/', (string) $decklist->getTags(), -1, PREG_SPLIT_NO_EMPTY);
        $user = $decklist->getUser();
        $row->authors = array_filter([$user->getUsername(), $user->getDisplayName(), $decklist->getSource()?->getLegacyAuthor()]);
        foreach ($decklist->getSlots() as $slot) {
            $row->cardKeys[self::cardKey($slot->getCard())] = true;
        }
        $cultures = [];
        foreach ($decklist->getCultures() as $row_culture) {
            $cultures[] = ['culture' => $row_culture->getCulture(), 'count' => $row_culture->getCount()];
        }
        usort($cultures, fn ($a, $b) => [$b['count'], $a['culture']->getName()] <=> [$a['count'], $b['culture']->getName()]);
        $row->cultures = $cultures;
        $row->thumbnail = $decklist;
        return $row;
    }

    /** whole, free_peoples or shadow, as a decklist's (PublishedDeck::getKind) */
    public function getKind(): string
    {
        if ($this->nbShadow === 0 && $this->nbFreePeoples > 0) {
            return Card::SIDE_FREE_PEOPLES;
        }
        if ($this->nbFreePeoples === 0 && $this->nbShadow > 0) {
            return Card::SIDE_SHADOW;
        }
        return 'whole';
    }
}
