<?php

namespace App\Tests\Services;

use App\Entity\Card;
use App\Entity\Format;
use App\Entity\Pack;
use App\Repository\FormatRepository;
use App\Services\FormatGuesser;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatGuesser::class)]
class FormatGuesserTest extends TestCase
{
    private FormatGuesser $guesser;

    protected function setUp(): void
    {
        $formats = [];
        foreach ([
            // code, sites, sets, pc errata, uses maps
            ['movie', 'King', range(1, 10), false, false],
            ['pc_movie', 'Multipath', array_merge(range(1, 10), range(51, 60), [101, 102, 103]), true, true],
            ['rotk_sta', 'King', range(1, 7), false, false],
            ['king_block', 'King', [7, 8, 10], false, false],
            ['fotr_block', 'Fellowship', [1, 2, 3], false, false],
            ['standard', 'Shadows and onwards', array_merge([0], range(7, 19)), false, false],
            ['hobbit', 'Hobbit', [30, 31, 32, 33], false, false],
        ] as $i => [$code, $sites, $sets, $pc, $maps]) {
            $format = new Format();
            $format->setCode($code);
            $format->setName($code);
            $format->setSites($sites);
            $format->setSets(array_map('strval', $sets));
            $format->setPcErrata($pc);
            $format->setUsesMaps($maps);
            $format->setHall(true);
            $format->setPosition($i);
            $formats[] = $format;
        }
        $repository = $this->createStub(FormatRepository::class);
        $repository->method('findSelectable')->willReturn($formats);
        $batches = $this->createStub(EntityRepository::class);
        $batches->method('findOneBy')->willReturn(null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($batches);
        $this->guesser = new FormatGuesser($repository, $em, dirname(__DIR__, 2) . '/config/format_guess.yaml');
    }

    private function card(int $set, ?string $siteBlock = null): Card
    {
        $pack = new Pack();
        $pack->setCode((string) $set);
        $card = new Card();
        $card->setPack($pack);
        $card->setSiteBlock($siteBlock);
        return $card;
    }

    /** King sites, every other card from set 7 (Ketura's hard case) */
    private function kingDeck(): array
    {
        $slots = [[$this->card(7), 'ring_bearer', 1], [$this->card(7), 'ring', 1], [$this->card(7), 'draw', 60]];
        foreach (range(1, 8) as $n) {
            $slots[] = [$this->card(7, 'King'), 'site', 1];
        }
        $slots[] = [$this->card(2, 'Fellowship'), 'site', 1]; // a stray site from another path is ignored
        return $slots;
    }

    private function codes(array $guess): array
    {
        return array_map(fn ($c) => $c['format']->getCode(), $guess['candidates']);
    }

    public function testKingPathAndSetSevenRanksByPopularity(): void
    {
        $guess = $this->guesser->guess($this->kingDeck());
        $this->assertSame('King', $guess['path']);
        $this->assertSame(['movie', 'pc_movie', 'rotk_sta', 'king_block'], $this->codes($guess));
    }

    public function testTheDateRulesOutLaterFormats(): void
    {
        // November 2003: Movie Block (after set 10) and the PC formats didn't exist yet
        $guess = $this->guesser->guess($this->kingDeck(), new DateTime('2003-12-01'));
        $this->assertSame(['rotk_sta', 'king_block'], $this->codes($guess));
    }

    public function testCardsOutsideTheSetsRuleFormatsOut(): void
    {
        $slots = $this->kingDeck();
        $slots[] = [$this->card(9), 'draw', 2]; // Reflections: only Movie Block and PC Movie allow it
        $this->assertSame(['movie', 'pc_movie'], $this->codes($this->guesser->guess($slots)));
    }

    public function testAMapPointsToFormatsThatUseMaps(): void
    {
        $slots = $this->kingDeck();
        $slots[] = [$this->card(101), 'map', 1];
        $this->assertSame(['pc_movie'], $this->codes($this->guesser->guess($slots)));
    }

    public function testASiteWithoutABlockGetsOneFromItsSet(): void
    {
        $slots = [[$this->card(1), 'draw', 60], [$this->card(1), 'site', 1], [$this->card(101), 'site', 1]];
        $this->assertSame('Fellowship', $this->guesser->guess($slots)['path']);
    }

    public function testShadowsSitesAndExcludedFormats(): void
    {
        $slots = [[$this->card(11), 'draw', 60], [$this->card(11, 'Shadows'), 'site', 9]];
        $this->assertSame(['standard'], $this->codes($this->guesser->guess($slots)));
    }
}
