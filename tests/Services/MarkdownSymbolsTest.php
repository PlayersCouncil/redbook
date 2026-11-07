<?php

namespace App\Tests\Services;

use App\Services\MarkdownSymbols;
use PHPUnit\Framework\TestCase;

/** Game symbols in markdown (Ketura, 2026-11-07) */
class MarkdownSymbolsTest extends TestCase
{
    private function render(string $text): string
    {
        [$text, $tokens] = MarkdownSymbols::protect($text);
        return MarkdownSymbols::restore($text, $tokens);
    }

    private function icons(string $text): array
    {
        preg_match_all('/data-tip="([^"]+)"/', $this->render($text), $m);
        return $m[1];
    }

    public function testCulturesByNameInAnyCase(): void
    {
        $this->assertSame(['Moria', 'Gondor', 'Uruk-hai', 'The One Ring'], $this->icons('[Moria] [gondor] [URUK-HAI] [The One Ring]'));
        $this->assertStringContainsString('rb-sym-moria', $this->render('[Moria]'));
        $this->assertStringContainsString('<span class="sr-only">[Moria]</span>', $this->render('[Moria]'));
    }

    public function testBlocksAndNumberedBlocks(): void
    {
        $this->assertSame(['Towers Block', 'King Block'], $this->icons('[Towers] and [King]'));
        $html = $this->render('Play 3T, [4K] or 5[T].');
        $this->assertSame(['Towers Block', 'King Block', 'Towers Block'], $this->icons('Play 3T, [4K] or 5[T].'));
        $this->assertStringContainsString('Play 3<span class="rb-sym"', $html);
        $this->assertStringContainsString(', 4<span class="rb-sym"', $html);
    }

    public function testTwilight(): void
    {
        $this->assertSame(['0 Twilight', '9 Twilight', 'X Twilight'], $this->icons('(0) (9) (X)'));
        // other values, and links, stay as they are
        $this->assertSame([], $this->icons('(10) (Y) (a)'));
    }

    public function testLeftAlone(): void
    {
        // not a known name, a markdown link, code, part of a word or a number
        $this->assertSame([], $this->icons('[Mordor] [Moria](http://x) `[Moria]` 13T 3Tx 9k players'));
        $this->assertSame([], $this->icons("```\n[Gondor] (3)\n```"));
    }
}
