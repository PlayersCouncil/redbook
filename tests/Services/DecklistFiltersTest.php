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

    public function testSubmittedDatesAreDaysOrNothing(): void
    {
        // as the calendar picker sends them (Ketura, 2026-11-06); a year alone or a bad day is left out
        $this->assertSame(['from' => '2004-03-01', 'to' => '2004-12-31'], $this->filters('from=2004-03-01&to=2004-12-31'));
        $this->assertSame([], $this->filters('from=2004&to=2004-02-30'));
    }

    public function testUnknownParametersAreLeftOut(): void
    {
        // the old search's sorts and half= aren't read any more (Ketura, 2026-11-06)
        $this->assertSame([], $this->filters('sort=likes&half=free_peoples&incomplete=1'));
        $this->assertSame(['author' => 'Kralik'], $this->filters('author=<b>Kralik</b>'));
    }
}
