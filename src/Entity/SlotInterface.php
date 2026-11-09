<?php

namespace App\Entity;

/**
 * Interface for an entity with a Card and a Quantity
 * @package App\Entity
 */
interface SlotInterface
{
    /**
     * @return CardInterface
     */
    public function getCard();

    /**
     * @param CardInterface $card
     */
    public function setCard(CardInterface $card);

    /**
     * @return int
     */
    public function getQuantity();

    /**
     * @param int $quantity
     */
    public function setQuantity($quantity);

    /** ring_bearer, ring, site, draw or map (App\Classes\DeckContent) */
    public function getRole(): string;

    public function setRole(string $role): void;

    /** The slot's key in a deck's content map */
    public function getContentKey(): string;

    /** Any version of the card will do (Ketura, 2026-11-08) */
    public function isGeneric(): bool;

    public function setGeneric(bool $generic): void;

    /** The card as a list reads it: "Legolas (any)" for a generic slot */
    public function getLabel(): string;
}
