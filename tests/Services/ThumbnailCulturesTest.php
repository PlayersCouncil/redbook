<?php

namespace App\Tests\Services;

use App\Entity\Culture;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckCulture;
use PHPUnit\Framework\TestCase;

/** A thumbnail without a card: the two cultures with the most cards, ties by name (Ketura, 2026-11-05) */
class ThumbnailCulturesTest extends TestCase
{
    private function culture(string $name, ?string $side): Culture
    {
        $culture = new Culture();
        $culture->setCode(strtolower($name));
        $culture->setName($name);
        $culture->setSide($side);
        return $culture;
    }

    public function testMostCardsThenFirstByName(): void
    {
        $deck = new PublishedDeck();
        foreach ([['Shire', 'free_peoples', 12], ['Moria', 'shadow', 18], ['Gandalf', 'free_peoples', 12], ['The One Ring', null, 40]] as [$name, $side, $count]) {
            $deck->getCultures()->add(new PublishedDeckCulture($deck, $this->culture($name, $side), $count));
        }
        $this->assertSame(['Moria', 'Gandalf'], array_map(fn ($c) => $c->getName(), $deck->getThumbnailCultures()));
    }

    public function testOneOrNone(): void
    {
        $deck = new PublishedDeck();
        $this->assertSame([], $deck->getThumbnailCultures());
        $deck->getCultures()->add(new PublishedDeckCulture($deck, $this->culture('Elven', 'free_peoples'), 30));
        $this->assertCount(1, $deck->getThumbnailCultures());
    }
}
