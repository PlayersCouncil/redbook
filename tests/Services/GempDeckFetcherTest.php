<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Card;
use App\Entity\Pack;
use App\Services\DeckImportService;
use App\Services\GempDeckFetcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Gemp's two deck pages (Ketura, 2026-11-08): a shared deck and a library deck, recorded
 * from play.lotrtcgpc.net. Same HTML; each card's wiki image names its exact printing.
 */
#[CoversClass(GempDeckFetcher::class)]
#[CoversClass(DeckImportService::class)]
class GempDeckFetcherTest extends TestCase
{
    private static function page(string $name): string
    {
        return GempDeckFetcher::htmlToText((string) file_get_contents(__DIR__ . '/../fixtures/gemp/' . $name));
    }

    /** A service that knows every card the page names, by the ID its image gives */
    private static function importer(string $text): DeckImportService
    {
        $pack = new Pack();
        $pack->setPosition(1);
        $cards = [];
        preg_match_all('/^(?:[\w\- ]+:\s*)?(?:\d+x\s+)?(.+?) \((\d+_\d+)\)$/m', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $name, $code]) {
            $card = new Card();
            $card->setCode($code);
            $card->setName($name);
            $card->setPack($pack);
            $card->setPosition((int) explode('_', $code)[1]);
            $card->setKind(((int) $code >= 50 && (int) $code < 100) ? 'errata' : 'card');
            $cards[$code] = $card;
        }
        $service = new DeckImportService(self::createStub(EntityManagerInterface::class));
        $service->setCards(array_values($cards));
        return $service;
    }

    public function testWikiImageCodes(): void
    {
        $this->assertSame('9_33', GempDeckFetcher::wikiImageCode('09', 'S', '033'));
        $this->assertSame('102_76', GempDeckFetcher::wikiImageCode('V2', 'S', '076'));
        $this->assertSame('57_359', GempDeckFetcher::wikiImageCode('07', 'E', '359'));
    }

    public function testSharedDeckPage(): void
    {
        $text = self::page('share.html');
        $this->assertStringContainsString("Ring-bearer: Isildur, Bearer of Heirlooms (9_33)\n", $text);
        $this->assertStringContainsString("Map: Journey of the King (102_76)\n", $text);
        $this->assertStringContainsString("2x Aragorn, Captain of Gondor (7_81)\n", $text);

        $result = self::importer($text)->parseTextImport($text);
        $this->assertSame([], $result['errors']);
        $deck = $result['decks'][0];
        $this->assertSame("Ghosts'n'Goblins", $deck['name']);
        $this->assertSame([], $deck['warnings']);
        $this->assertSame('', $deck['description']);
        $this->assertSame(1, $deck['content']['rb:9_33']);
        $this->assertSame(1, $deck['content']['4_1']);
        $this->assertSame(1, $deck['content']['102_76']);
        // the errata printing, from its image
        $this->assertSame(1, $deck['content']['57_359']);
        $this->assertSame(2, $deck['content']['7_81']);
        $this->assertSame(1 + 1 + 1 + 9 + 38 + 38, array_sum($deck['content']));
    }

    public function testLibraryDeckPage(): void
    {
        $text = self::page('lib.html');
        $deck = self::importer($text)->parseTextImport($text)['decks'][0];
        $this->assertSame('01 FOTR Aragorn Starter Deck (Gondor / Isengard)', $deck['name']);
        $this->assertSame([], $deck['warnings']);
        $this->assertSame(1, $deck['content']['rb:1_290']);
        $this->assertSame(1 + 1 + 9 + 30 + 30, array_sum($deck['content']));
    }
}
