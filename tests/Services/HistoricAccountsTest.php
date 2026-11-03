<?php

namespace App\Tests\Services;

use App\Entity\User;
use App\Services\AccountMerger;
use App\Services\SeedDeckImporter;
use PHPUnit\Framework\TestCase;

/**
 * Historic accounts (Ketura, 2026-11-02): one per source and author name, display
 * names, aliases from merges, and the dates the archive import derives.
 */
class HistoricAccountsTest extends TestCase
{
    public function testHistoricKeyIgnoresCaseAndSpacing(): void
    {
        $this->assertSame('decktech:zach "yodamuppet" marshall', SeedDeckImporter::historicKey('decktech', '  Zach  "yodamuppet"   Marshall '));
        $this->assertSame(SeedDeckImporter::historicKey('gpn', 'TheOneSpoon'), SeedDeckImporter::historicKey('gpn', 'theonespoon'));
        $this->assertNotSame(SeedDeckImporter::historicKey('gpn', 'TheOneSpoon'), SeedDeckImporter::historicKey('decktech', 'TheOneSpoon'));
        $this->assertSame('gpn:?', SeedDeckImporter::historicKey('gpn', '   '));
    }

    public function testDateUsedPrefersEffectiveDateThenEventThenPosted(): void
    {
        $this->assertSame('2004-07', SeedDeckImporter::dateUsed(['effectiveDate' => '2004-07', 'tournament' => ['date' => '2004-06-01'], 'postedDate' => '2004-08-01']));
        $this->assertSame('2004-06-01', SeedDeckImporter::dateUsed(['tournament' => ['date' => '2004-06-01'], 'postedDate' => '2004-08-01']));
        $this->assertSame('2004-08-01', SeedDeckImporter::dateUsed(['tournament' => ['date' => 'summer'], 'postedDate' => '2004-08-01']));
        $this->assertNull(SeedDeckImporter::dateUsed([]));
    }

    public function testEffectiveDateIsTheEndOfThePeriod(): void
    {
        $this->assertSame('2004-07-31', SeedDeckImporter::effectiveDate(['effectiveDate' => '2004-07', 'postedDate' => '2004-07-24'])->format('Y-m-d'));
        $this->assertSame('2002-12-31', SeedDeckImporter::effectiveDate(['postedDate' => '2002'])->format('Y-m-d'));
    }

    public function testArchiveDateFromWaybackUrl(): void
    {
        $this->assertSame('2004-08-30', SeedDeckImporter::archiveDate('https://web.archive.org/web/20040830194921/http://forums.decipher.com:80/viewtopic.php?t=10981'));
        $this->assertNull(SeedDeckImporter::archiveDate('http://www.decktech.net/lotr/decks/decks.php?deck=20055'));
        $this->assertNull(SeedDeckImporter::archiveDate(null));
    }

    public function testDisplayNameFallsBackToUsername(): void
    {
        $user = new User();
        $user->setUsername('historic_12');
        $this->assertSame('historic_12', $user->getDisplayName());
        $user->setDisplayName('  Mike Mendoza ');
        $this->assertSame('Mike Mendoza', $user->getDisplayName());
        $user->setDisplayName('');
        $this->assertNull($user->getRawDisplayName());
        $this->assertSame('historic_12', $user->getDisplayName());
    }

    public function testAliasesAreKeptOnce(): void
    {
        $user = new User();
        $user->addAlias('Mike Mendoza', 'decipher-forums');
        $user->addAlias('mike mendoza', 'decipher-forums');
        $user->addAlias('Mike Mendoza', 'decktech');
        $this->assertCount(2, $user->getAliases());
    }

    public function testResolveFollowsMerges(): void
    {
        $a = $this->user(1);
        $b = $this->user(2);
        $c = $this->user(3);
        $a->setMergedInto($b);
        $b->setMergedInto($c);
        $this->assertSame($c, AccountMerger::resolve($a));
        $this->assertSame($c, AccountMerger::resolve($c));
        // a loop can't hang it
        $c->setMergedInto($a);
        AccountMerger::resolve($a);
        $this->addToAssertionCount(1);
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setId($id);
        return $user;
    }
}
