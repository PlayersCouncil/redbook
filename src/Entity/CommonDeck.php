<?php

namespace App\Entity;

/**
 * Base class for both deck and deck-list entities.
 * @package App\Entity
 */
abstract class CommonDeck implements CommonDeckInterface
{
    /**
     * The parts of the deck for the text export (templates/Export/default.txt.twig),
     * which DeckImportService reads back.
     */
    public function getTextExport()
    {
        $slots = $this->getSlots();
        return [
            'name' => $this->getName(),
            'version' => $this->getVersion(),
            'target_format' => $this->getTargetFormat(),
            'ring_bearer' => $slots->getRingBearer(),
            'ring' => $slots->getRing(),
            'sites' => $slots->getSites()->getSlots(),
            'free_peoples' => $this->sortForExport($slots->getFreePeoples()->getSlots()),
            'shadow' => $this->sortForExport($slots->getShadow()->getSlots()),
            'map' => $slots->getMap(),
            'draw_deck_size' => $slots->getDrawDeck()->countCards(),
            'gemp' => $slots->toGempString(),
        ];
    }

    private function sortForExport(array $slots): array
    {
        usort($slots, fn (SlotInterface $a, SlotInterface $b) => [$a->getCard()->getCulture()?->getName(), $a->getCard()->getType()->getName(), $a->getCard()->getFullName()]
            <=> [$b->getCard()->getCulture()?->getName(), $b->getCard()->getType()->getName(), $b->getCard()->getFullName()]);
        return $slots;
    }
}
