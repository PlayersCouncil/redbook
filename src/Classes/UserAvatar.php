<?php

namespace App\Classes;

use App\Entity\Card;
use App\Entity\User;

/**
 * A user's avatar as Decklist/thumbnail.html.twig draws it (Ketura, 2026-11-11): their card,
 * or their culture filling the tile, or neither (the template's default)
 */
final class UserAvatar implements ThumbnailSubject
{
    public function __construct(private readonly User $user)
    {
    }

    public function getThumbnailCard(): ?Card
    {
        return $this->user->getAvatarMode() === 'card' ? $this->user->getAvatarCard() : null;
    }

    public function getThumbnailCropFor(?Card $card): ?array
    {
        $crop = $this->user->getAvatarCrop();
        return $card && $crop && ($crop['code'] ?? null) === $card->getCode() ? $crop : null;
    }

    public function getThumbnailCultures(): array
    {
        $culture = $this->user->getAvatarCulture();
        return $culture ? [['code' => $culture->getCode(), 'name' => $culture->getName(), 'side' => $culture->getSide() ?? 'free_peoples', 'corner' => 'full']] : [];
    }
}
