<?php

namespace App\Twig;

use App\Repository\FormatRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** format_name(code): a format's name, or the code when it isn't known (the text export, Ketura 2026-11-04) */
class FormatExtension extends AbstractExtension
{
    public function __construct(private readonly FormatRepository $formats)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('format_name', fn (?string $code) => $code ? ($this->formats->findOneByCode($code)?->getName() ?? $code) : '')];
    }
}
