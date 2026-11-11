<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\PublishedDeck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The reader's description column, rendered on the server (Ketura, 2026-11-04): the starting
 * fellowship, bid and starting notes above the description, the card notes below it. Being
 * in the page itself, the column is whole on the first paint instead of waiting for the
 * browser's card data. A saved deck's page reads the same way (Ketura, 2026-11-08).
 */
class ReaderNotes
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $router,
        private readonly Texts $texts,
    ) {
    }

    /**
     * @return array{groups: array<array{name: string, cards: array<array{code: string, label: string, href: string}>}>,
     *     bid: ?string, starting_html: ?string, notes: array<array{code: string, label: string, href: string, in_deck: bool, html: string}>,
     *     description_html: string, posted_by: string}
     *     groups: the starting fellowships (Ketura, 2026-11-11), each with its companions in the order they're played;
     *     a name left blank is the view's to give ("Starting Fellowship", "Starting Fellowship 2")
     */
    public function build(PublishedDeck|Deck $decklist): array
    {
        $groups = $decklist->getStartingGroups();
        $annotations = $decklist->getAnnotations();
        $cards = $this->cards(array_merge($decklist->getStarting(), array_column($annotations, 'code')));
        $inDeck = [];
        $generic = [];
        foreach ($decklist->getSlots() as $slot) {
            $inDeck[$slot->getCard()->getCode()] = true;
            if ($slot->isGeneric()) {
                $generic[$slot->getCard()->getCode()] = true;
            }
        }

        $view = [
            'groups' => [], 'bid' => $decklist->getStartingBid(), 'starting_html' => null, 'notes' => [],
            'description_html' => $decklist instanceof PublishedDeck ? (string) $decklist->getDescriptionHtml() : $this->texts->markdown((string) $decklist->getDescriptionMd()),
            'posted_by' => $this->postedBy($decklist),
        ];
        foreach ($groups as $group) {
            $row = ['name' => $group['name'], 'cards' => []];
            foreach ($group['codes'] as $code) {
                if (isset($cards[$code])) {
                    // a companion any version will do for reads as in the list: "Legolas (any)" (Ketura, 2026-11-08)
                    $row['cards'][] = isset($generic[$code])
                        ? ['label' => CardVersions::genericLabel($cards[$code]), 'generic' => true] + $this->card($cards[$code])
                        : $this->card($cards[$code]);
                }
            }
            $view['groups'][] = $row;
        }
        if ((string) $decklist->getStartingNotes() !== '') {
            $view['starting_html'] = $this->texts->markdown($decklist->getStartingNotes());
        }
        foreach ($annotations as $row) {
            $code = $row['code'];
            $card = isset($cards[$code]) ? $this->card($cards[$code]) : ['code' => $code, 'label' => $code, 'href' => '#', 'image' => null];
            $view['notes'][] = $card + ['in_deck' => isset($inDeck[$code]), 'html' => $this->texts->markdown($row['text'] ?? '')];
        }
        return $view;
    }

    /**
     * Who posted the deck (Ketura, 2026-11-08), which isn't always whose account it's under:
     * an archive deck's poster, else its author as the archive or the deck's Historical Deck
     * Information gives it, else the account's name
     */
    private function postedBy(PublishedDeck|Deck $decklist): string
    {
        $source = $decklist instanceof PublishedDeck ? $decklist->getSource() : null;
        $deck = $decklist instanceof PublishedDeck ? $decklist->getParent() : $decklist;
        $names = [
            $source?->getReporterName(),
            $source?->getLegacyAuthor(),
            $deck?->getHistoric()['author'] ?? null,
            $decklist->getUser()->getDisplayName(),
        ];
        foreach ($names as $name) {
            if (trim((string) $name) !== '') {
                return trim((string) $name);
            }
        }
        return '';
    }

    /** @return array<string, Card> */
    private function cards(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (!$codes) {
            return [];
        }
        $found = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => $codes]) as $card) {
            $found[$card->getCode()] = $card;
        }
        return $found;
    }

    private function card(Card $card): array
    {
        return [
            'code' => $card->getCode(),
            'label' => $card->getFullName(),
            'href' => $this->router->generate('cards_zoom', ['card_code' => $card->getCode()]),
            // a card note shows the card beside it (Ketura, 2026-11-08)
            'image' => $card->getImageUrl(),
        ];
    }
}
