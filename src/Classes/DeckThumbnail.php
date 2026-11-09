<?php

namespace App\Classes;

use App\Entity\Card;

/**
 * What Decklist/thumbnail.html.twig needs for a deck that isn't published (Ketura, 2026-11-07):
 * its cultures' banner, picked as a decklist's is (PublishedDeck::pickThumbnailCultures).
 */
final class DeckThumbnail implements ThumbnailSubject
{
    public function __construct(private readonly array $cultures)
    {
    }

    public function getThumbnailCard(): ?Card
    {
        return null;
    }

    public function getThumbnailCultures(): array
    {
        return $this->cultures;
    }

    public function getThumbnailCropFor(?Card $card): ?array
    {
        return null;
    }
}
