<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use JsonSerializable;
use Ramsey\Uuid\UuidInterface;

interface DeckInterface extends CommonDeckInterface, JsonSerializable
{
    /**
     * @param string $tags
     */
    public function setTags($tags);

    /**
     * @return string
     */
    public function getTags();

    /**
     * @param DeckSlotInterface $slot
     */
    public function addSlot(DeckSlotInterface $slot);

    /**
     * @param DeckSlotInterface $slot
     */
    public function removeSlot(DeckSlotInterface $slot);

    /**
     * @param PublishedDeckInterface $child
     */
    public function addChild(PublishedDeckInterface $child);

    /**
     * @param PublishedDeckInterface $child
     */
    public function removeChild(PublishedDeckInterface $child);

    /**
     * @return Collection
     */
    public function getChildren();

    /**
     * @param DeckInterface $child
     */
    public function addDeckChild(DeckInterface $child);

    /**
     * @param PublishedDeckInterface $child
     */
    public function removeDeckChild(DeckInterface $child);

    /**
     * @return Collection
     */
    public function getDeckChildren();

    /**
     * @param DeckRevisionInterface $change
     */
    public function addChange(DeckRevisionInterface $change);

    /**
     * @param DeckRevisionInterface $change
     */
    public function removeChange(DeckRevisionInterface $change);

    /**
     * @return Collection
     */
    public function getChanges();

    /**
     * @param PackInterface $lastPack
     */
    public function setLastPack(?PackInterface $lastPack = null);

    /**
     * @return PackInterface
     */
    public function getLastPack();

    /**
     * @param PublishedDeckInterface|null $parent
     */
    public function setParent(?PublishedDeckInterface $parent = null);

    /**
     * @return PublishedDeckInterface
     */
    public function getParent();

    /**
     * @param DeckInterface|null $parent
     */
    public function setParentDeck(?DeckInterface $parent = null);

    /**
     * @return DeckInterface
     */
    public function getParentDeck();

    /**
     * @param int $majorVersion
     */
    public function setMajorVersion($majorVersion);

    /**
     * @return int
     */
    public function getMajorVersion();

    /**
     * @param int $minorVersion
     */
    public function setMinorVersion($minorVersion);

    /**
     * @return int
     */
    public function getMinorVersion();

    /**
     * @return array
     */
    public function getHistory();

    /**
     * @return bool
     */
    public function getIsUnsaved();

    /**
     * @param UuidInterface $uuid
     */
    public function setUuid($uuid);

    /**
     * @return UuidInterface|null
     */
    public function getUuid();

    public function isShared(): bool;

    public function setShared(bool $shared): void;
}
