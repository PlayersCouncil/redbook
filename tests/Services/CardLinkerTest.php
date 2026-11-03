<?php

namespace App\Tests\Services;

use App\Services\CardLinker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The parts of [[card]] links that need no database: name keys, and keeping links
 * out of code. Resolution against the cards runs in the e2e tests.
 */
class CardLinkerTest extends TestCase
{
    public function testNormalizeFoldsAccentsAndPunctuation(): void
    {
        $this->assertSame('aragorn heir to the white city', CardLinker::normalize('Aragorn, Heir to the White City'));
        $this->assertSame('ulaire nertea messenger of dol guldur', CardLinker::normalize('•Úlairë Nertëa, Messenger of Dol Guldur'));
        $this->assertSame('the ones ring', CardLinker::normalize("The One's Ring"));
    }

    public function testInitialsNeedTwoWords(): void
    {
        $this->assertSame('httwc', CardLinker::initials('Heir to the White City'));
        $this->assertSame('', CardLinker::initials('Greenleaf'));
    }

    public function testProtectSkipsCode(): void
    {
        $linker = new CardLinker($this->createMock(EntityManagerInterface::class), $this->createMock(UrlGeneratorInterface::class));
        [$md, $links] = $linker->protect("Play [[HTTWC|the king]] and `[[3_38]]`.\n\n```\n[[1_50]]\n```\nthen [[3R38]]");
        $this->assertSame(['rbcardlink0x' => ['HTTWC', 'the king'], 'rbcardlink1x' => ['3R38', null]], $links);
        $this->assertStringContainsString('`[[3_38]]`', $md);
        $this->assertStringContainsString("```\n[[1_50]]\n```", $md);
    }
}
