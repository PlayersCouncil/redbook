<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Classes\ThumbnailCrop;
use App\Entity\Card;
use App\Entity\Library;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A library's own thumbnail, and the hand framing every thumbnail shares (Ketura, 2026-11-09) */
#[CoversClass(Library::class)]
#[CoversClass(ThumbnailCrop::class)]
class LibraryThumbnailTest extends TestCase
{
    private static function card(string $code): Card
    {
        $card = new Card();
        $card->setCode($code);
        return $card;
    }

    public function testNothingOfItsOwnIsAuto(): void
    {
        $library = new Library();
        $this->assertSame('auto', $library->getThumbnailMode());
        $this->assertNull($library->getThumbnailCard());
        $this->assertSame([], $library->getThumbnailCultures());
    }

    public function testOneCultureFillsTheTileTwoShareIt(): void
    {
        $library = new Library();
        $library->setThumbnailCultures([['code' => 'shire', 'name' => 'Shire', 'side' => 'free_peoples']]);
        $this->assertSame('cultures', $library->getThumbnailMode());
        $this->assertSame('full', $library->getThumbnailCultures()[0]['corner']);

        // any combination: two Shadow cultures, the first top left
        $library->setThumbnailCultures([
            ['code' => 'moria', 'name' => 'Moria', 'side' => 'shadow'],
            ['code' => 'sauron', 'name' => 'Sauron', 'side' => 'shadow'],
        ]);
        $parts = $library->getThumbnailCultures();
        $this->assertSame(['moria', 'sauron'], array_column($parts, 'code'));
        $this->assertSame(['fp', 'shadow'], array_column($parts, 'corner'));
    }

    public function testACardWithItsFraming(): void
    {
        $library = new Library();
        $gandalf = self::card('1_72');
        $library->setThumbnailCard($gandalf);
        $library->setThumbnailCrop(['code' => '1_72', 'w' => 150.0, 'l' => -20.0, 't' => -10.0]);
        $this->assertSame('card', $library->getThumbnailMode());
        $this->assertSame($gandalf, $library->getThumbnailCard());
        $this->assertSame(150.0, $library->getThumbnailCropFor($gandalf)['w']);
        $this->assertNull($library->getThumbnailCropFor(self::card('1_50')));
        $this->assertSame([], $library->getThumbnailCultures());
    }

    public function testTheFramingIsCheckedBeforeItsKept(): void
    {
        $this->assertSame(['code' => '1_72', 'w' => 150.0, 'l' => -20.0, 't' => -10.0], ThumbnailCrop::sanitize('{"code":"1_72","w":150,"l":-20,"t":-10}', ['1_72']));
        // another card's, or one that leaves the tile uncovered
        $this->assertNull(ThumbnailCrop::sanitize(['code' => '1_50', 'w' => 150, 'l' => -20, 't' => -10], ['1_72']));
        $this->assertNull(ThumbnailCrop::sanitize(['code' => '1_72', 'w' => 90, 'l' => 0, 't' => 0], ['1_72']));
        $this->assertNull(ThumbnailCrop::sanitize(['code' => '1_72', 'w' => 150, 'l' => -60, 't' => 0], ['1_72']));
        $this->assertNull(ThumbnailCrop::sanitize('not json', ['1_72']));
    }
}
