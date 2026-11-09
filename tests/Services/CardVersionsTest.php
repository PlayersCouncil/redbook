<?php

namespace App\Tests\Services;

use App\Classes\DeckContent;
use App\Services\CardVersions;
use PHPUnit\Framework\TestCase;

/** Cards any version will do for (Ketura, 2026-11-08): their keys, and titles folded as the archive folds them */
class CardVersionsTest extends TestCase
{
    public function testTitlesFoldAsTheArchiveDoes(): void
    {
        $this->assertSame('eowyn', CardVersions::fold('Éowyn'));
        $this->assertSame(CardVersions::fold('Ranger’s Sword'), CardVersions::fold("Ranger's Sword"));
        $this->assertSame('ulaire attea', CardVersions::fold('Úlairë Attëa'));
        $this->assertSame('sword and shield', CardVersions::fold('Sword & Shield'));
        $this->assertSame('the one ring', CardVersions::fold('  The One-Ring!  '));
    }

    public function testKeysCarryAnyAfterTheRingBearer(): void
    {
        $this->assertSame('rb:any:1_290', DeckContent::key('1_290', DeckContent::ROLE_RING_BEARER, true));
        $this->assertSame('any:1_50', DeckContent::key('1_50', DeckContent::ROLE_DRAW, true));
        $this->assertSame('1_50', DeckContent::key('1_50', DeckContent::ROLE_DRAW));
        $this->assertSame(['1_290', true, true], DeckContent::parseKey('rb:any:1_290'));
        $this->assertSame(['1_50', false, true], DeckContent::parseKey('any:1_50'));
        $this->assertSame(['1_290', true, false], DeckContent::parseKey('rb:1_290'));
        $this->assertSame(['1_50', false, false], DeckContent::parseKey('1_50'));
    }
}
