<?php

namespace App\Classes;

use App\Entity\SlotInterface;
use ArrayAccess;
use Countable;
use IteratorAggregate;

/**
 * A deck's slots (phase3/03): one per card and role.
 */
interface SlotCollectionInterface extends Countable, IteratorAggregate, ArrayAccess
{
    public function add(SlotInterface $slot);

    public function removeElement(SlotInterface $slot);

    /** Number of cards, copies included */
    public function countCards(): int;

    /** @return SlotInterface[] */
    public function getSlots(): array;

    /** @return array<string, int> the content map (DeckContent) */
    public function getContent(): array;

    public function byRole(string $role): self;

    public function getRingBearer(): ?SlotInterface;

    public function getRing(): ?SlotInterface;

    public function getSites(): self;

    public function getDrawDeck(): self;

    public function getMap(): ?SlotInterface;

    public function getFreePeoples(): self;

    public function getShadow(): self;

    /** @return array<string, array{culture: \App\Entity\Culture, count: int}> draw deck cards per culture code */
    public function getCultures(): array;

    public function filterByType(string $typeCode): self;

    public function filterByCulture(string $cultureCode): self;

    /** @return array<int, array{pack: \App\Entity\PackInterface, nb: int}> sets used, in release order */
    public function getIncludedPacks(): array;

    /** The Gemp deck string (DeckContent::toGempString) */
    public function toGempString(): string;
}
