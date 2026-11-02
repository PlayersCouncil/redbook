<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\Pack;
use App\Services\DeckImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeckImportService::class)]
#[CoversClass(DeckContent::class)]
class DeckImportServiceTest extends TestCase
{
    private DeckImportService $service;

    protected function setUp(): void
    {
        $fotr = new Pack();
        $fotr->setPosition(2);
        $errata = new Pack();
        $errata->setPosition(25);
        $cards = [];
        foreach ([
            ['1_2', 'The One Ring', 'The Ruling Ring', '1R2', $fotr, 'card'],
            ['1_290', 'Frodo', 'Son of Drogo', '1C290', $fotr, 'card'],
            ['1_50', 'Legolas', 'Greenleaf', '1R50', $fotr, 'card'],
            ['51_50', 'Legolas', 'Greenleaf', '1R50', $errata, 'errata'],
            ['1_319', 'Bag End', null, '1U319', $fotr, 'card'],
            ['1_165', 'Goblin Runner', null, '1C165', $fotr, 'card'],
        ] as [$code, $name, $subtitle, $collInfo, $pack, $kind]) {
            $card = new Card();
            $card->setCode($code);
            $card->setName($name);
            $card->setSubtitle($subtitle);
            $card->setCollInfo($collInfo);
            $card->setPack($pack);
            $card->setPosition((int) explode('_', $code)[1]);
            $card->setKind($kind);
            $cards[] = $card;
        }
        $this->service = new DeckImportService($this->createStub(EntityManagerInterface::class));
        $this->service->setCards($cards);
    }

    public function testGempDeckString(): void
    {
        $result = $this->service->parseTextImport("My deck\n1_290|1_2|1_319|1_50,1_50,1_165*,99_99");
        $this->assertSame([], $result['errors']);
        $deck = $result['decks'][0];
        $this->assertSame('My deck', $deck['name']);
        $this->assertSame(['rb:1_290' => 1, '1_2' => 1, '1_319' => 1, '1_50' => 2, '1_165' => 1], $deck['content']);
        $this->assertSame(['Unknown card 99_99'], $deck['warnings']);
    }

    public function testCardListWithSections(): void
    {
        $text = <<<TXT
        Elves and Goblins
        Ring-bearer: Frodo, Son of Drogo
        Ring: The One Ring, The Ruling Ring
        Adventure deck
        Bag End
        Free Peoples (3)
        2x •Legolas, Greenleaf
        Shadow
        3 Goblin Runner (1C165)
        Goblin Runner x1
        Not A Card
        TXT;
        $deck = $this->service->parseTextImport($text)['decks'][0];
        $this->assertSame('Elves and Goblins', $deck['name']);
        $this->assertSame(['rb:1_290' => 1, '1_2' => 1, '1_319' => 1, '1_50' => 2, '1_165' => 4], $deck['content']);
        $this->assertSame(['Couldn\'t find a card for "Not A Card"'], $deck['warnings']);
    }

    public function testNamesPreferTheDecipherCardAndIdsPickExactly(): void
    {
        $deck = $this->service->parseTextImport("x\n1 Legolas, Greenleaf\n1 Legolas, Greenleaf (51_50)")['decks'][0];
        $this->assertSame(['1_50' => 1, '51_50' => 1], $deck['content']);
    }

    public function testSeveralDecksAndAnEmptyOne(): void
    {
        $result = $this->service->parseTextImport("A\n1 Bag End\n===\nB\nnothing here\n=====\nC\n1_290|1_2||");
        $this->assertCount(2, $result['decks']);
        $this->assertSame(['No cards found in "B"'], $result['errors']);
    }

    public function testGempStringRoundTrip(): void
    {
        $string = DeckContent::toGempString([['1_50', 'draw', 2], ['1_290', 'ring_bearer', 1], ['1_2', 'ring', 1], ['1_319', 'site', 1], ['1_165', 'draw', 1]]);
        $this->assertSame('1_290|1_2|1_319|1_50,1_50,1_165', $string);
        $this->assertSame(['rb:1_290' => 1, '1_2' => 1, '1_319' => 1, '1_50' => 2, '1_165' => 1], DeckContent::fromGempString($string));
    }

    public function testRedBookTextExportPrefersItsGempLine(): void
    {
        $text = "Elves\nFormat: fotr_block\n\nRing-bearer: Frodo, Son of Drogo (1_290)\nFree Peoples\n2x Legolas, Greenleaf (1_50)\n\nGemp: 1_290|1_2|1_319|1_50,1_50";
        $deck = $this->service->parseTextImport($text)['decks'][0];
        $this->assertSame('Elves', $deck['name']);
        $this->assertSame(['rb:1_290' => 1, '1_2' => 1, '1_319' => 1, '1_50' => 2], $deck['content']);
    }
}
