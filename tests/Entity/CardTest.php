<?php

namespace App\Tests\Entity;

use App\Entity\Card;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Card::class)]
class CardTest extends TestCase
{
    public function testFullNameIncludesTheSubtitle(): void
    {
        $card = new Card();
        $card->setName('Legolas');
        $this->assertSame('Legolas', $card->getFullName());
        $card->setSubtitle('Greenleaf');
        $this->assertSame('Legolas, Greenleaf', $card->getFullName());
    }

    public function testKindAndSide(): void
    {
        $card = new Card();
        $this->assertFalse($card->isErrata());
        $card->setKind(Card::KIND_ERRATA);
        $this->assertTrue($card->isErrata());

        $card->setSide(Card::SIDE_SHADOW);
        $this->assertTrue($card->isShadow());
        $this->assertFalse($card->isFreePeoples());
    }
}
