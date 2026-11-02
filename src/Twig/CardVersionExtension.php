<?php

namespace App\Twig;

use App\Entity\Card;
use App\Services\CardVersionResolver;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** card_image(card, date): the card's image as of a deck's effective date (decision 0021) */
class CardVersionExtension extends AbstractExtension
{
    public function __construct(private readonly CardVersionResolver $resolver)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('card_image', fn (Card $card, ?DateTimeInterface $date = null) => $this->resolver->imageUrl($card, $date))];
    }
}
