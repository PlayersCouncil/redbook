<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\Gemp\DeckValidation;
use App\Services\PublishGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Only legal decks, or fragments of one side, are published (Ketura, 2026-11-08) */
#[CoversClass(PublishGate::class)]
class PublishGateTest extends TestCase
{
    private const FRAGMENT_LINES = [
        "Deck doesn't have a Ring-bearer",
        "Deck doesn't have a One Ring",
        "Deck doesn't have a Map (try 'Journey of the King' if converting a Movie deck).",
        'Deck contains below minimum number of cards: 30<60.',
        'Deck contains different number of Shadow and Free peoples cards.',
        'Deck has 0 sites instead of 9.',
    ];

    private static function validation(int $fp, int $shadow, array $problems, ?array $withErrata = null): DeckValidation
    {
        return new DeckValidation('fotr_block', 'Fellowship Block', $problems === [], $problems,
            $withErrata === null ? null : $withErrata === [], $withErrata, $fp, $shadow);
    }

    public function testALegalDeck(): void
    {
        $verdict = PublishGate::verdict(self::validation(30, 30, []), 'fotr_block');
        $this->assertTrue($verdict['ok']);
        $this->assertSame('whole', $verdict['kind']);
    }

    public function testLegalOnceItsErrataApply(): void
    {
        $verdict = PublishGate::verdict(self::validation(30, 30, ["Non-errata version of an errata'd card: Gandalf"], []), 'pc_fotr_block');
        $this->assertTrue($verdict['ok']);
    }

    public function testAFragmentOfEitherSide(): void
    {
        $this->assertSame('fragment', PublishGate::verdict(self::validation(30, 0, self::FRAGMENT_LINES), 'fotr_block')['kind']);
        $this->assertSame('fragment', PublishGate::verdict(self::validation(0, 34, self::FRAGMENT_LINES), 'fotr_block')['kind']);
    }

    public function testAFragmentWithItsOwnRingBearerAndSomeSites(): void
    {
        $this->assertTrue(PublishGate::verdict(self::validation(31, 0, ['Deck has 4 sites instead of 9.', 'Deck contains below minimum number of cards: 31<60.']), 'fotr_block')['ok']);
    }

    public function testNotAFragment(): void
    {
        // too small, or both sides
        $this->assertSame('illegal', PublishGate::verdict(self::validation(29, 0, self::FRAGMENT_LINES), 'fotr_block')['reason']);
        $this->assertSame('illegal', PublishGate::verdict(self::validation(30, 1, self::FRAGMENT_LINES), 'fotr_block')['reason']);
    }

    public function testAFragmentRejectingACard(): void
    {
        foreach ([
            'Card not from valid set: •Aragorn, King of Gondor',
            'Card is X-listed: Sleep, Caradhras',
            'Deck contains more of the same card than allowed - Goblin Runner (5>4): Goblin Runner',
            'Card assigned as Ring-bearer cannot bear the ring',
            'Site does not use supported block: Rohirrim Camp',
            'Deck has multiple of the same site number: 2',
            '(any) Legolas: no other version is legal in Fellowship Block',
            'Unknown card 99_99',
        ] as $line) {
            $verdict = PublishGate::verdict(self::validation(30, 0, [...self::FRAGMENT_LINES, $line]), 'fotr_block');
            $this->assertFalse($verdict['ok'], $line);
            $this->assertContains($line, $verdict['problems']);
        }
    }

    public function testNoAnswerOrNoFormat(): void
    {
        $this->assertSame('unavailable', PublishGate::verdict(null, 'fotr_block')['reason']);
        $this->assertSame('no_format', PublishGate::verdict(null, null)['reason']);
    }
}
