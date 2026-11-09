<?php

namespace App\Classes;

use App\Entity\Card;

/**
 * Anything with a thumbnail (Ketura, 2026-11-09): a decklist, a deck not yet published
 * (DeckThumbnail), a library. Decklist/thumbnail.html.twig draws any of them: the card's
 * picture, framed by hand or by its template, or else the culture banner, or else a book.
 */
interface ThumbnailSubject
{
    public function getThumbnailCard(): ?Card;

    /** @return array{code: string, w: float, l: float, t: float}|null the hand framing, when it's for this card */
    public function getThumbnailCropFor(?Card $card): ?array;

    /** @return array<array{code: string, name: string, corner: string, side: string}> corner: fp, shadow or full */
    public function getThumbnailCultures(): array;
}
