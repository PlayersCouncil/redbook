<?php

namespace App\Entity;

/**
 * What can be cheered, set on fire and crowned (Ketura, 2026-11-11): a decklist, a library, a
 * comment. App\Services\Reactions keeps the counts; the entity only carries them.
 */
interface Reactable
{
    /** How far up a user has taken it: nothing, a Cheers, on fire, crowned */
    public const NONE = 0;
    public const CHEERS = 1;
    public const FIRE = 2;
    public const CROWN = 3;

    /**
     * What each step adds to its score, and to its author's reputation (Ketura, 2026-11-11):
     * a Cheers 1, a fire 2 more, a crown 3 more
     */
    public const POINTS = [self::CHEERS => 1, self::FIRE => 2, self::CROWN => 3];

    public function getId();

    /** Whoever made it: their own Cheers comes with it, and they can't fire or crown it */
    public function getUser();

    /** @return array{0: int, 1: int, 2: int} Cheers, fires, crowns */
    public function getReactionCounts(): array;

    public function setReactionCounts(int $cheers, int $fires, int $crowns): void;
}
