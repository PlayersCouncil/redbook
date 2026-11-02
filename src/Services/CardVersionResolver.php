<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\CardVersion;
use DateTimeInterface;

/**
 * Which version of a card applied on a date (decision 0021, phase3/03): the
 * latest revision from the matching issuer released on or before the date.
 *
 * A Decipher blueprint (1_50) follows Decipher's own errata; its Players Council
 * errata are separate blueprints (51_50), which follow the PC revisions of the
 * card they replace. A PC errata blueprint on a date before its first revision
 * shows that first revision, since the blueprint didn't exist yet.
 */
class CardVersionResolver
{
    public function resolve(Card $card, DateTimeInterface $date): ?CardVersion
    {
        $base = $card->isErrata() ? ($card->getErrataOf() ?? $card) : $card;
        $versions = $base->getVersions()->toArray();
        if (!$versions) {
            return null;
        }
        $wanted = $card->isErrata() ? CardVersion::ISSUER_PC : CardVersion::ISSUER_DECIPHER;
        $candidates = array_filter($versions, fn (CardVersion $v) => $card->isErrata()
            ? $v->getIssuer() === $wanted
            : ($v->getRevision() === 0 || $v->getIssuer() === $wanted));
        if (!$candidates) {
            return null;
        }
        usort($candidates, fn (CardVersion $a, CardVersion $b) => $a->getRevision() <=> $b->getRevision());
        $chosen = null;
        foreach ($candidates as $version) {
            $released = $version->getReleaseDate();
            if ($version->getRevision() === 0 || $released === null || $released <= $date) {
                $chosen = $version;
            }
        }
        return $chosen ?? $candidates[0];
    }

    /** The image of the version that applied on the date, else the card's own */
    public function imageUrl(Card $card, ?DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return $card->getImageUrl();
        }
        return $this->resolve($card, $date)?->getImageUrl() ?? $card->getImageUrl();
    }
}
