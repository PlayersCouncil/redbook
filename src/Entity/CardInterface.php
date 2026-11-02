<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Serializable;

/**
 * A LOTR TCG card. See Card.
 */
interface CardInterface extends Serializable
{
    public function getId();
    public function setId($id);
    public function getPosition();
    public function setPosition($position);
    public function getCode();
    public function setCode($code);
    public function getKind(): string;
    public function isErrata(): bool;
    public function getName();
    public function setName($name);
    public function getSubtitle(): ?string;
    public function getFullName(): string;
    public function getIsUnique();
    public function getSide(): ?string;
    public function isFreePeoples(): bool;
    public function isShadow(): bool;
    public function getTwilight(): ?int;
    public function getStrength();
    public function getVitality(): ?int;
    public function getResistance(): ?int;
    public function getSiteNumber(): ?int;
    public function getText();
    public function getFlavor();
    public function getImageUrl();
    public function getCulture(): ?Culture;
    public function getErrataOf(): ?Card;
    /** @return Collection<int, CardVersion> */
    public function getVersions(): Collection;
    public function getRarity(): ?RarityInterface;
    public function getPack();
    public function getType();
    public function getFaction();
    public function getDateCreation();
    public function getDateUpdate();
    public function addReview(ReviewInterface $review);
    public function removeReview(ReviewInterface $review);
    public function getReviews();
}
