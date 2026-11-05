<?php

namespace App\Tests\Services;

use App\Services\SeedDeckImporter;
use PHPUnit\Framework\TestCase;

/** Seed batches v1 and v2 read the same way (Ketura, 2026-11-04) */
class SeedDeckNormalizeTest extends TestCase
{
    public function testV1LegacyAuthorAndTournament(): void
    {
        $deck = SeedDeckImporter::normalize([
            'legacyAuthor' => 'Mas3rTech',
            'tournament' => ['name' => 'PSQ', 'date' => '2004-04', 'placing' => '1st', 'players' => 20],
        ], 1);
        $this->assertSame('Mas3rTech', $deck['owner']);
        $this->assertNull($deck['people']);
        $this->assertSame([['eventName' => 'PSQ', 'date' => '2004-04', 'placingAsWritten' => '1st', 'players' => 20, 'location' => null, 'pilot' => null]], $deck['plays']);
        $this->assertSame('en', $deck['language']);
    }

    public function testV2FiledUnderTheAuthorWhenThereIsOne(): void
    {
        $deck = SeedDeckImporter::normalize(['reporter' => ['name' => 'NBarden'], 'author' => ['name' => 'zugaj', 'displayName' => 'Robert Zugaj']], 2);
        $this->assertSame('zugaj', $deck['owner']);
        $this->assertSame('Robert Zugaj', $deck['ownerDisplay']);
        $this->assertSame('NBarden', $deck['people']['reporter']['name']);

        $deck = SeedDeckImporter::normalize(['reporter' => ['name' => 'Cobra']], 2);
        $this->assertSame('Cobra', $deck['owner']);
    }

    public function testV2PlaysCommentsAndLanguage(): void
    {
        $deck = SeedDeckImporter::normalize([
            'reporter' => ['name' => 'Cobra'],
            'language' => 'de',
            'plays' => [
                ['eventName' => 'Continentals', 'pilot' => ['name' => 'Robert Zugaj (Poland)'], 'placingAsWritten' => '3rd place'],
                ['eventId' => '2002-07-05-origins', 'partId' => 'day-2', 'resultPlayer' => 'Hays, Clint'],
                ['placingAsWritten' => 'no event named'],
            ],
            'comments' => [['author' => ['name' => 'Kyle'], 'text' => 'Strong', 'format' => 'text']],
        ], 2);
        $this->assertCount(2, $deck['plays'], 'a play without an event is dropped');
        $this->assertSame('Robert Zugaj (Poland)', $deck['plays'][0]['pilot']);
        // only an event ID: it names the play until the events are imported
        $this->assertSame('2002-07-05-origins', $deck['plays'][1]['eventName']);
        $this->assertSame('Hays, Clint', $deck['plays'][1]['resultPlayer']);
        $this->assertSame('Kyle', $deck['comments'][0]['author']);
        $this->assertSame('de', $deck['language']);
    }
}
