<?php

namespace App\Tests\Services;

use App\Services\LibraryManager;
use PHPUnit\Framework\TestCase;

/** What a library revision says changed (Ketura, 2026-11-09) */
class LibraryHistoryDiffTest extends TestCase
{
    private static function snapshot(array $entries, array $texts = []): array
    {
        return $texts + [
            'name' => 'Worlds 2004', 'description' => '', 'notes' => '', 'tags' => '',
            'thumbnail' => ['card' => null, 'crop' => null, 'cultures' => null],
            'entries' => array_map(fn ($e) => ['decklist' => $e[0], 'annotation' => $e[1] ?? ''], $entries),
        ];
    }

    public function testNothingChanged(): void
    {
        $a = self::snapshot([[1, 'first'], [2]]);
        $this->assertSame([], LibraryManager::diff($a, $a));
    }

    public function testAddedRemovedAnnotated(): void
    {
        $diff = LibraryManager::diff(self::snapshot([[1], [2, 'old'], [3]]), self::snapshot([[2, 'new'], [3], [4]]));
        $this->assertSame([4], $diff['added']);
        $this->assertSame([1], $diff['removed']);
        $this->assertSame([2], $diff['annotated']);
        // the decks kept are in the same order, so nothing moved
        $this->assertArrayNotHasKey('reordered', $diff);
    }

    public function testReordered(): void
    {
        $diff = LibraryManager::diff(self::snapshot([[1], [2], [3]]), self::snapshot([[3], [1], [2]]));
        $this->assertSame(['reordered' => true], $diff);
    }

    public function testFields(): void
    {
        $diff = LibraryManager::diff(
            self::snapshot([[1]]),
            self::snapshot([[1]], ['name' => 'Worlds 2005', 'thumbnail' => ['card' => '1_2', 'crop' => null, 'cultures' => null]])
        );
        $this->assertSame(['fields' => ['name', 'thumbnail']], $diff);
    }
}
