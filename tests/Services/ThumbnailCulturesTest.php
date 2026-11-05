<?php

namespace App\Tests\Services;

use App\Entity\Card;
use App\Entity\Culture;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckCulture;
use PHPUnit\Framework\TestCase;

/**
 * A thumbnail without a card (Ketura, 2026-11-05): a whole deck's main Free Peoples and main
 * Shadow culture; a fragment's main culture alone. Ties go to the first by name.
 */
class ThumbnailCulturesTest extends TestCase
{
    private function deck(int $fp, int $shadow, array $cultures): PublishedDeck
    {
        $deck = new PublishedDeck();
        foreach (['nbFreePeoples' => $fp, 'nbShadow' => $shadow] as $field => $value) {
            (new \ReflectionProperty(PublishedDeck::class, $field))->setValue($deck, $value);
        }
        foreach ($cultures as [$name, $side, $count]) {
            $culture = new Culture();
            $culture->setCode(strtolower($name));
            $culture->setName($name);
            $culture->setSide($side);
            $deck->getCultures()->add(new PublishedDeckCulture($deck, $culture, $count));
        }
        return $deck;
    }

    private function shown(PublishedDeck $deck): array
    {
        return array_map(fn ($part) => $part['culture']->getName() . ':' . $part['corner'], $deck->getThumbnailCultures());
    }

    public function testAWholeDeckShowsTheMainCultureOfEachSide(): void
    {
        $deck = $this->deck(30, 30, [
            ['Shire', Card::SIDE_FREE_PEOPLES, 12], ['Gandalf', Card::SIDE_FREE_PEOPLES, 12],
            ['Moria', Card::SIDE_SHADOW, 18], ['Sauron', Card::SIDE_SHADOW, 12], ['The One Ring', null, 40],
        ]);
        $this->assertSame(['Gandalf:fp', 'Moria:shadow'], $this->shown($deck));
    }

    public function testAFragmentShowsItsMainCultureAlone(): void
    {
        $deck = $this->deck(36, 0, [['Elven', Card::SIDE_FREE_PEOPLES, 20], ['Gandalf', Card::SIDE_FREE_PEOPLES, 16]]);
        $this->assertSame(['Elven:full'], $this->shown($deck));
        // Gollum's Shadow cards in a Shadow fragment: still the one culture, whatever its side
        $deck = $this->deck(0, 30, [['Gollum', Card::SIDE_FREE_PEOPLES, 18], ['Moria', Card::SIDE_SHADOW, 12]]);
        $this->assertSame(['Gollum:full'], $this->shown($deck));
    }

    public function testNoCultures(): void
    {
        $this->assertSame([], $this->shown($this->deck(0, 0, [])));
    }
}
