<?php

namespace App\Entity;

use App\Classes\DeckContent;
use Doctrine\ORM\Mapping as ORM;

/**
 * The part of a LOTR deck a slot belongs to (phase3/03): ring_bearer, ring, site,
 * draw or map. Only the Ring-bearer is chosen; the others follow from the card's
 * type (DeckContent::roleForType).
 */
trait SlotRoleTrait
{
    #[ORM\Column(name: 'role', type: 'string', length: 12, nullable: false, options: ['default' => 'draw'])]
    protected string $role = DeckContent::ROLE_DRAW;

    /**
     * Any version of the card will do (Ketura, 2026-11-08): "any Frodo", "any site 3". The
     * card is the version it was made from.
     */
    #[ORM\Column(name: 'generic', type: 'boolean', nullable: false, options: ['default' => false])]
    protected bool $generic = false;

    public function isGeneric(): bool
    {
        return $this->generic;
    }

    public function setGeneric(bool $generic): void
    {
        $this->generic = $generic;
    }

    /** How the slot reads in a list: the card, or "Legolas (any)", "Any site 3", "Any site" */
    public function getLabel(): string
    {
        return $this->generic ? \App\Services\CardVersions::genericLabel($this->card) : $this->card->getFullName();
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    /** This slot's key in a deck's content map (DeckContent) */
    public function getContentKey(): string
    {
        return DeckContent::key($this->card->getCode(), $this->role, $this->generic);
    }
}
