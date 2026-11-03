<?php

namespace App\Tests\Services;

use App\Services\SeedDeckImporter;
use Parsedown;
use PHPUnit\Framework\TestCase;

/**
 * Archive write-ups keep their line breaks once rendered, and plain text isn't
 * mistaken for Markdown headings.
 */
class SeedDeckTextTest extends TestCase
{
    private function html(string $text, string $format = 'text'): string
    {
        return (new Parsedown())->text(SeedDeckImporter::toMarkdown($text, $format));
    }

    public function testLineBreaksSurvive(): void
    {
        $html = $this->html("Played this at Regionals.\nWent 4-1.\n\nThe plan:\nswarm early");
        $this->assertSame("<p>Played this at Regionals.<br />\nWent 4-1.</p>\n<p>The plan:<br />\nswarm early</p>", $html);
    }

    public function testHeadingLookalikesStayText(): void
    {
        $html = $this->html("Free Peoples\n-----------\n#1 seed going in\n====");
        $this->assertStringNotContainsString('<h', $html);
        $this->assertStringContainsString('-----------', $html);
        $this->assertStringContainsString('#1 seed going in', $html);
    }

    public function testHtmlParagraphsAndBreaks(): void
    {
        $html = $this->html("<p>First line<br>second\nline</p>\n<p>Next &amp; last</p>", 'html');
        $this->assertSame("<p>First line<br />\nsecond line</p>\n<p>Next &amp; last</p>", $html);
    }

    public function testWindowsLineEndings(): void
    {
        $this->assertSame("a  \nb", SeedDeckImporter::toMarkdown("a\r\nb\r\n", 'text'));
    }

    public function testCandidatesFromAnAmbiguousReason(): void
    {
        $line = SeedDeckImporter::withCandidates(['line' => 'Sting, Bane o/t 8 Legs', 'section' => 'draw',
            'reason' => 'ambiguous: 1_313 Sting, 5_116 Sting, Baggins Heirloom, 8_113 Sting, Bane of the Eight Legs']);
        $this->assertSame(['1_313', '5_116', '8_113'], $line['candidates']);

        $line = SeedDeckImporter::withCandidates(['line' => 'Strider', 'reason' => 'no card by that name was in print as of 2004-08-10 (probably a nickname)']);
        $this->assertArrayNotHasKey('candidates', $line);

        // the batch's own list wins
        $line = SeedDeckImporter::withCandidates(['line' => 'x', 'reason' => 'ambiguous: 1_1 A', 'candidates' => ['2_2']]);
        $this->assertSame(['2_2'], $line['candidates']);
    }
}
