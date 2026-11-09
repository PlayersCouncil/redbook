<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * cultures | cultures_by_side: the cultures as every culture picker lays them out (Ketura,
 * 2026-11-06; shared 2026-11-09), the Free Peoples then the Shadow, each in the order given
 * (by name) except that Gollum ends the Free Peoples and Men, Orc and Uruk-hai end the Shadow.
 * Cultures with no side (the One Ring) are left out.
 */
class CultureOrderExtension extends AbstractExtension
{
    public const LATE = ['gollum', 'men', 'orc', 'uruk_hai'];

    public function getFilters(): array
    {
        return [new TwigFilter('cultures_by_side', [self::class, 'bySide'])];
    }

    /** @return array{free_peoples: array, shadow: array} */
    public static function bySide(iterable $cultures): array
    {
        $sides = ['free_peoples' => [[], []], 'shadow' => [[], []]];
        foreach ($cultures as $culture) {
            $side = $culture->getSide();
            if (isset($sides[$side])) {
                $sides[$side][in_array($culture->getCode(), self::LATE, true) ? 1 : 0][] = $culture;
            }
        }
        return array_map(fn ($parts) => array_merge($parts[0], $parts[1]), $sides);
    }
}
