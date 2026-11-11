<?php

namespace App\Tests\Classes;

use App\Classes\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

/** Markdown back from comments kept only as html (Ketura, 2026-11-11) */
class HtmlToMarkdownTest extends TestCase
{
    public function testParagraphsEmphasisLinksAndCardLinks(): void
    {
        $html = '<p>Run <strong>four</strong> of <a href="/card/1_12" class="card-tip rb-cardlink" data-code="1_12">Gimli, Dwarf of Erebor</a>.</p>'
            . '<p>See <a href="https://example.org/x">this guide</a> or https://example.org.</p>';
        $this->assertSame("Run **four** of [[Gimli, Dwarf of Erebor]].\n\nSee [this guide](https://example.org/x) or https://example.org.", HtmlToMarkdown::convert($html));
    }

    public function testQuotesListsAndBreaks(): void
    {
        $html = "<blockquote>\n<p>Said before</p>\n</blockquote>\n<ul><li>one</li><li><em>two</em></li></ul><p>a<br>b</p>";
        $this->assertSame("> Said before\n\n- one\n- *two*\n\na  \nb", HtmlToMarkdown::convert($html));
    }

    public function testEmpty(): void
    {
        $this->assertSame('', HtmlToMarkdown::convert(''));
    }
}
