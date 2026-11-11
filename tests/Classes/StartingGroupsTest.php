<?php

namespace App\Tests\Classes;

use App\Classes\StartingGroups;
use PHPUnit\Framework\TestCase;

/** Several starting fellowships, each named (Ketura, 2026-11-11) */
class StartingGroupsTest extends TestCase
{
    public function testTheOneListOfBeforeIsOneUnnamedGroup(): void
    {
        $this->assertSame([['name' => '', 'codes' => ['1_12', '1_50']]], StartingGroups::normalize(['1_12', '1_50', '1_12']));
        $this->assertSame([['name' => '', 'codes' => ['1_12']]], StartingGroups::normalize('["1_12"]'));
        $this->assertSame([], StartingGroups::normalize(null));
        $this->assertSame([], StartingGroups::normalize([]));
    }

    public function testGroupsAreCleanedAndEmptyOnesDropped(): void
    {
        $groups = StartingGroups::normalize([
            ['name' => '  Going   <b>first</b> ', 'codes' => ['1_12', 'nonsense', '1_50']],
            ['name' => '', 'codes' => []],
            ['name' => 'vs. Ringwraith', 'codes' => []],
            ['name' => '', 'cards' => ['2_121']],
        ]);
        $this->assertSame([
            ['name' => 'Going first', 'codes' => ['1_12', '1_50']],
            ['name' => 'vs. Ringwraith', 'codes' => []],
            ['name' => '', 'codes' => ['2_121']],
        ], $groups);
    }

    public function testFilterAndUnion(): void
    {
        $groups = [['name' => '', 'codes' => ['1_12', '1_50']], ['name' => '', 'codes' => ['1_50', '9_9']], ['name' => 'Kept', 'codes' => ['9_9']]];
        $kept = StartingGroups::filter($groups, fn ($c) => $c !== '9_9');
        $this->assertSame([['name' => '', 'codes' => ['1_12', '1_50']], ['name' => '', 'codes' => ['1_50']], ['name' => 'Kept', 'codes' => []]], $kept);
        $this->assertSame(['1_12', '1_50', '9_9'], StartingGroups::union($groups));
    }
}
