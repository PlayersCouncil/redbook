<?php

namespace App\Tests\Services;

use App\Entity\Card;
use App\Entity\CardVersion;
use App\Services\CardVersionResolver;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardVersionResolver::class)]
class CardVersionResolverTest extends TestCase
{
    private Card $decipher;
    private Card $errata;

    protected function setUp(): void
    {
        $this->decipher = new Card();
        $this->decipher->setCode('1_50');
        $this->decipher->setImageUrl('current.jpg');
        foreach ([
            [0, null, null, 'printed.jpg'],
            [1, CardVersion::ISSUER_DECIPHER, '2002-06-01', 'decipher-errata.jpg'],
            [2, CardVersion::ISSUER_PC, '2021-03-20', 'pc-1.jpg'],
            [3, CardVersion::ISSUER_PC, '2024-12-06', 'pc-2.jpg'],
        ] as [$revision, $issuer, $date, $image]) {
            $version = new CardVersion();
            $version->setWikiId('LOTR-EN01S050.' . $revision);
            $version->setRevision($revision);
            $version->setIssuer($issuer);
            $version->setReleaseDate($date ? new DateTime($date) : null);
            $version->setImageUrl($image);
            $this->decipher->addVersion($version);
        }
        $this->errata = new Card();
        $this->errata->setCode('51_50');
        $this->errata->setKind(Card::KIND_ERRATA);
        $this->errata->setErrataOf($this->decipher);
    }

    public function testDecipherBlueprintFollowsDecipherErrataOnly(): void
    {
        $resolver = new CardVersionResolver();
        $this->assertSame('printed.jpg', $resolver->imageUrl($this->decipher, new DateTime('2001-12-01')));
        $this->assertSame('decipher-errata.jpg', $resolver->imageUrl($this->decipher, new DateTime('2002-06-01')), 'on the release day');
        $this->assertSame('decipher-errata.jpg', $resolver->imageUrl($this->decipher, new DateTime('2025-01-01')), 'PC errata are another blueprint');
    }

    public function testErrataBlueprintFollowsPcRevisions(): void
    {
        $resolver = new CardVersionResolver();
        $this->assertSame('pc-1.jpg', $resolver->imageUrl($this->errata, new DateTime('2003-01-01')), 'before it existed: its first version');
        $this->assertSame('pc-1.jpg', $resolver->imageUrl($this->errata, new DateTime('2024-12-05')));
        $this->assertSame('pc-2.jpg', $resolver->imageUrl($this->errata, new DateTime('2024-12-06')));
    }

    public function testNoDateIsTheCurrentImage(): void
    {
        $this->assertSame('current.jpg', (new CardVersionResolver())->imageUrl($this->decipher, null));
    }
}
