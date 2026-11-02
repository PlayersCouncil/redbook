<?php

namespace App\Classes;

use App\Entity\Card;
use App\Entity\SlotInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Traversable;

/**
 * Decorator for a collection of LOTR deck slots (phase3/03).
 */
class SlotCollectionDecorator implements SlotCollectionInterface
{
    public function __construct(protected Collection $slots)
    {
    }

    public function add(SlotInterface $slot)
    {
        return $this->slots->add($slot);
    }

    public function removeElement(SlotInterface $slot)
    {
        return $this->slots->removeElement($slot);
    }

    public function count(): int
    {
        return $this->slots->count();
    }

    public function getIterator(): Traversable
    {
        return $this->slots->getIterator();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->slots->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->slots->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->slots->offsetSet($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->slots->offsetUnset($offset);
    }

    public function countCards(): int
    {
        $count = 0;
        foreach ($this->slots as $slot) {
            $count += $slot->getQuantity();
        }
        return $count;
    }

    public function getSlots(): array
    {
        return $this->slots->toArray();
    }

    public function getContent(): array
    {
        $content = [];
        foreach ($this->slots as $slot) {
            $content[$slot->getContentKey()] = $slot->getQuantity();
        }
        ksort($content, SORT_NATURAL);
        return $content;
    }

    private function filter(callable $keep): self
    {
        return new self(new ArrayCollection(array_values(array_filter($this->slots->toArray(), $keep))));
    }

    public function byRole(string $role): self
    {
        return $this->filter(fn (SlotInterface $slot) => $slot->getRole() === $role);
    }

    public function getRingBearer(): ?SlotInterface
    {
        return $this->byRole(DeckContent::ROLE_RING_BEARER)->getSlots()[0] ?? null;
    }

    public function getRing(): ?SlotInterface
    {
        return $this->byRole(DeckContent::ROLE_RING)->getSlots()[0] ?? null;
    }

    public function getSites(): self
    {
        $sites = $this->byRole(DeckContent::ROLE_SITE)->getSlots();
        usort($sites, fn ($a, $b) => [$a->getCard()->getSiteNumber() ?? 99, $a->getCard()->getName()]
            <=> [$b->getCard()->getSiteNumber() ?? 99, $b->getCard()->getName()]);
        return new self(new ArrayCollection($sites));
    }

    public function getDrawDeck(): self
    {
        return $this->byRole(DeckContent::ROLE_DRAW);
    }

    public function getMap(): ?SlotInterface
    {
        return $this->byRole(DeckContent::ROLE_MAP)->getSlots()[0] ?? null;
    }

    public function getFreePeoples(): self
    {
        return $this->getDrawDeck()->filter(fn (SlotInterface $slot) => $slot->getCard()->getSide() === Card::SIDE_FREE_PEOPLES);
    }

    public function getShadow(): self
    {
        return $this->getDrawDeck()->filter(fn (SlotInterface $slot) => $slot->getCard()->getSide() === Card::SIDE_SHADOW);
    }

    public function getCultures(): array
    {
        $cultures = [];
        foreach ($this->getDrawDeck() as $slot) {
            $culture = $slot->getCard()->getCulture();
            if ($culture === null) {
                continue;
            }
            $cultures[$culture->getCode()] ??= ['culture' => $culture, 'count' => 0];
            $cultures[$culture->getCode()]['count'] += $slot->getQuantity();
        }
        uasort($cultures, fn ($a, $b) => [$b['count'], $a['culture']->getName()] <=> [$a['count'], $b['culture']->getName()]);
        return $cultures;
    }

    public function filterByType(string $typeCode): self
    {
        return $this->filter(fn (SlotInterface $slot) => $slot->getCard()->getType()->getCode() === $typeCode);
    }

    public function filterByCulture(string $cultureCode): self
    {
        return $this->filter(fn (SlotInterface $slot) => $slot->getCard()->getCulture()?->getCode() === $cultureCode);
    }

    public function getIncludedPacks(): array
    {
        $packs = [];
        foreach ($this->slots as $slot) {
            $pack = $slot->getCard()->getPack();
            $packs[$pack->getId()] ??= ['pack' => $pack, 'nb' => 0];
            $packs[$pack->getId()]['nb'] += $slot->getQuantity();
        }
        $packs = array_values($packs);
        usort($packs, fn ($a, $b) => $a['pack']->getPosition() <=> $b['pack']->getPosition());
        return $packs;
    }

    public function toGempString(): string
    {
        $triples = [];
        foreach ($this->slots as $slot) {
            $triples[] = [$slot->getCard()->getCode(), $slot->getRole(), (int) $slot->getQuantity()];
        }
        return DeckContent::toGempString($triples);
    }

    /**
     * Draw deck slots grouped for display: side, then culture, then type, sorted
     * by twilight and name (phase3/03, "Deckbuilder layout").
     *
     * @return array<string, array<string, SlotInterface[]>> side => culture name => slots
     */
    public function getDrawDeckBySideAndCulture(): array
    {
        $groups = [Card::SIDE_FREE_PEOPLES => [], Card::SIDE_SHADOW => []];
        foreach ($this->getDrawDeck() as $slot) {
            $card = $slot->getCard();
            $side = $card->getSide() ?? Card::SIDE_FREE_PEOPLES;
            $groups[$side][$card->getCulture()?->getName() ?? ''][] = $slot;
        }
        foreach ($groups as &$cultures) {
            ksort($cultures);
            foreach ($cultures as &$slots) {
                usort($slots, fn ($a, $b) => [$a->getCard()->getType()->getName(), $a->getCard()->getTwilight() ?? 0, $a->getCard()->getFullName()]
                    <=> [$b->getCard()->getType()->getName(), $b->getCard()->getTwilight() ?? 0, $b->getCard()->getFullName()]);
            }
        }
        return $groups;
    }
}
