<?php

namespace App\Tests\Services;

use App\Entity\PublishedDeck;
use PHPUnit\Framework\TestCase;

/**
 * A thumbnail without a card (Ketura, 2026-11-05): a whole deck's main Free Peoples and main
 * Shadow culture, by each side's cards, so Gollum can be either; a fragment's main culture
 * alone. Ties go to the first by name.
 */
class ThumbnailCulturesTest extends TestCase
{
    private function shown(array $counts): array
    {
        $byside = [];
        foreach ($counts as [$side, $name, $count]) {
            $byside[$side][strtolower($name)] = ['name' => $name, 'count' => $count];
        }
        return array_map(fn ($part) => $part['name'] . ':' . $part['corner'], PublishedDeck::pickThumbnailCultures($byside));
    }

    public function testAWholeDeckShowsTheMainCultureOfEachSide(): void
    {
        $this->assertSame(['Gandalf:fp', 'Moria:shadow'], $this->shown([
            ['free_peoples', 'Shire', 12], ['free_peoples', 'Gandalf', 12],
            ['shadow', 'Moria', 18], ['shadow', 'Sauron', 12],
        ]));
    }

    public function testGollumOnEitherSideByItsCardsThere(): void
    {
        // Gollum's Shadow cards make it the Shadow culture; its Free Peoples cards don't count there
        $this->assertSame(['Shire:fp', 'Gollum:shadow'], $this->shown([
            ['free_peoples', 'Shire', 20], ['free_peoples', 'Gollum', 10],
            ['shadow', 'Gollum', 18], ['shadow', 'Moria', 12],
        ]));
        // and both corners, for now (Ketura will want one tile when both are Gollum)
        $this->assertSame(['Gollum:fp', 'Gollum:shadow'], $this->shown([
            ['free_peoples', 'Gollum', 20], ['shadow', 'Gollum', 30],
        ]));
    }

    public function testAFragmentShowsItsMainCultureAlone(): void
    {
        $this->assertSame(['Elven:full'], $this->shown([['free_peoples', 'Elven', 20], ['free_peoples', 'Gandalf', 16]]));
        $this->assertSame(['Gollum:full'], $this->shown([['shadow', 'Gollum', 18], ['shadow', 'Moria', 12]]));
    }

    public function testNoCultures(): void
    {
        $this->assertSame([], $this->shown([]));
    }
}
