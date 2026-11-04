<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\PublishedDeck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The reader's description column, rendered on the server (Ketura, 2026-11-04): the starting
 * fellowship, bid and starting notes above the description, the card notes below it. Being
 * in the page itself, the column is whole on the first paint instead of waiting for the
 * browser's card data.
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
     * @return array{starting: array<array{code: string, label: string, href: string}>, twilight: int,
     *     bid: ?string, starting_html: ?string, notes: array<array{code: string, label: string, href: string, in_deck: bool, html: string}>}
     */
    public function build(PublishedDeck $decklist): array
    {
        $starting = $decklist->getStarting();
        $annotations = $decklist->getAnnotations();
        $cards = $this->cards(array_merge($starting, array_column($annotations, 'code')));
        $inDeck = [];
        foreach ($decklist->getSlots() as $slot) {
            $inDeck[$slot->getCard()->getCode()] = true;
        }

        $view = ['starting' => [], 'twilight' => 0, 'bid' => $decklist->getStartingBid(), 'starting_html' => null, 'notes' => []];
        foreach ($starting as $code) {
            if (isset($cards[$code])) {
                $view['starting'][] = $this->card($cards[$code]);
                $view['twilight'] += (int) $cards[$code]->getTwilight();
            }
        }
        if ($decklist->getStartingNotes()) {
            $view['starting_html'] = $this->texts->markdown($decklist->getStartingNotes());
        }
        foreach ($annotations as $row) {
            $code = $row['code'];
            $card = isset($cards[$code]) ? $this->card($cards[$code]) : ['code' => $code, 'label' => $code, 'href' => '#'];
            $view['notes'][] = $card + ['in_deck' => isset($inDeck[$code]), 'html' => $this->texts->markdown($row['text'] ?? '')];
        }
        return $view;
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
        ];
    }
}
