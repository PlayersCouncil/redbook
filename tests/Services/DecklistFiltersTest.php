<?php

namespace App\Tests\Services;

use App\Services\PublishedDeckManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/** The decklists page's filters, read from the address (Ketura, 2026-11-05) */
class DecklistFiltersTest extends TestCase
{
    private function filters(string $query): array
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/decklists?' . $query));
        $manager = new PublishedDeckManager(
            $this->createStub(EntityManagerInterface::class),
            $stack,
            $this->createStub(RouterInterface::class),
            new NullLogger()
        );
        return $manager->getFilters();
    }

    public function testDefaultsAreLeftOut(): void
    {
        // all three kinds, the newest first, empty fields: nothing to filter on
        $this->assertSame([], $this->filters('kind[]=whole&kind[]=free_peoples&kind[]=shadow&sort=new&name=&format='));
    }

    public function testKindsAndCultures(): void
    {
        $f = $this->filters('kind[]=shadow&kind[]=bogus&culture[]=dwarven&culture[]=dwarven&culture[]=a%27b');
        $this->assertSame(['shadow'], $f['kind']);
        $this->assertSame(['dwarven', 'ab'], $f['culture']);
    }

    public function testTheOldSearchStillReads(): void
    {
        $this->assertSame(['sort' => 'rated'], $this->filters('sort=likes'));
        $this->assertSame([], $this->filters('sort=date'));
        $this->assertSame(['kind' => ['free_peoples']], $this->filters('half=free_peoples'));
        $this->assertSame(['author' => 'Kralik'], $this->filters('author=<b>Kralik</b>&incomplete=1'));
    }
}
