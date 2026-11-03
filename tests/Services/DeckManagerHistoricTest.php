<?php

namespace App\Tests\Services;

use App\Services\DeckManager;
use PHPUnit\Framework\TestCase;

/**
 * Historical Deck Information: the fields kept, and Date Used as the deck's
 * effective date (a month-only date is the end of the month, Ketura 2026-11-02).
 */
class DeckManagerHistoricTest extends TestCase
{
    public function testEndOfPeriod(): void
    {
        $this->assertSame('2004-12-31', DeckManager::endOfPeriod('2004')->format('Y-m-d'));
        $this->assertSame('2004-07-31', DeckManager::endOfPeriod('2004-07')->format('Y-m-d'));
        $this->assertSame('2004-02-29', DeckManager::endOfPeriod('2004-02')->format('Y-m-d'));
        $this->assertSame('2004-07-24', DeckManager::endOfPeriod('2004-07-24')->format('Y-m-d'));
        $this->assertSame('2004-07-04', DeckManager::endOfPeriod(' 2004-7-4 ')->format('Y-m-d'));
    }

    public function testEndOfPeriodRejectsNonsense(): void
    {
        $this->assertNull(DeckManager::endOfPeriod(''));
        $this->assertNull(DeckManager::endOfPeriod('July 2004'));
        $this->assertNull(DeckManager::endOfPeriod('2004-13'));
        $this->assertNull(DeckManager::endOfPeriod('2004-02-30'));
    }

    public function testNormalizeHistoricKeepsKnownFieldsWithoutMarkup(): void
    {
        $historic = DeckManager::normalizeHistoric(json_encode([
            'author' => ' Josh Cornwell-Mott ',
            'event' => '<b>San Diego</b> PSE',
            'date_used' => '2004-07',
            'unknown' => 'dropped',
        ]));
        $this->assertSame('Josh Cornwell-Mott', $historic['author']);
        $this->assertSame('San Diego PSE', $historic['event']);
        $this->assertSame('2004-07', $historic['date_used']);
        $this->assertSame('', $historic['notes']);
        $this->assertArrayNotHasKey('unknown', $historic);
        $this->assertSame(DeckManager::HISTORIC_FIELDS, array_keys($historic));
    }

    public function testNotHistoricIsNull(): void
    {
        $this->assertNull(DeckManager::normalizeHistoric(''));
        $this->assertNull(DeckManager::normalizeHistoric(null));
        $this->assertNull(DeckManager::normalizeHistoric('not json'));
    }
}
