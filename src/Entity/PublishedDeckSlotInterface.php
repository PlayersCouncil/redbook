<?php

namespace App\Entity;

/**
 * @package App\Entity
 */
interface PublishedDeckSlotInterface extends SlotInterface
{
    /**
     * @param int $id
     */
    public function setId($id);

    /**
     * @return int
     */
    public function getId();

    /**
     * @param PublishedDeck $decklist
     */
    public function setDecklist(?PublishedDeck $decklist = null);

    /**
     * @return PublishedDeck
     */
    public function getDecklist();
}
