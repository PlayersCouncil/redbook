<?php

namespace App\Tests\Services\Gemp;

use App\Services\Gemp\DeckValidation;
use App\Services\Gemp\FormatsUpdater;
use App\Services\Gemp\GempClient;
use App\Services\Gemp\GempRejectedException;
use App\Services\Gemp\GempUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(GempClient::class)]
#[CoversClass(DeckValidation::class)]
#[CoversClass(FormatsUpdater::class)]
class GempClientTest extends TestCase
{
    private function client(array $responses): GempClient
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new GempClient('http://gemp.test', new NullLogger(), $http);
    }

    public function testValidationSplitsGempsMultiLineProblems(): void
    {
        // As test.lotrtcgpc.net answered on 2026-11-01
        $gemp = $this->client([new Response(200, [], json_encode([
            'formatCode' => 'pc_fotr_block', 'formatName' => 'Fellowship Block (PC)',
            'freePeoplesCount' => 4, 'shadowCount' => 2, 'valid' => false,
            'problems' => [
                "Deck contains below minimum number of cards: 6<60.\nDeck contains different number of Shadow and Free peoples cards.\n",
                'Card assigned as Site is not really a site.',
            ],
            'validWithErrata' => false,
            'problemsWithErrata' => ["Deck contains below minimum number of cards: 6<60.\n"],
        ]))]);

        $result = $gemp->validate('pc_fotr_block', '1_290|1_2|1_319|1_50');
        $this->assertFalse($result->valid);
        $this->assertSame([
            'Deck contains below minimum number of cards: 6<60.',
            'Deck contains different number of Shadow and Free peoples cards.',
            'Card assigned as Site is not really a site.',
        ], $result->problems);
        $this->assertSame(['Deck contains below minimum number of cards: 6<60.'], $result->problemsWithErrata);
        $this->assertSame(4, $result->freePeoplesCount);
        $this->assertEquals($result, DeckValidation::fromArray($result->toArray()));
    }

    public function testFormatWithoutErrataHasNullErrataResult(): void
    {
        $gemp = $this->client([new Response(200, [], json_encode([
            'formatCode' => 'fotr_block', 'formatName' => 'Fellowship Block', 'valid' => true, 'problems' => [],
            'validWithErrata' => null, 'problemsWithErrata' => null, 'freePeoplesCount' => 30, 'shadowCount' => 30,
        ]))]);
        $result = $gemp->validate('fotr_block', 'x');
        $this->assertTrue($result->valid);
        $this->assertNull($result->validWithErrata);
        $this->assertNull($result->problemsWithErrata);
    }

    public function testBadDeckIsRejectedWithGempsMessage(): void
    {
        $gemp = $this->client([new Response(400, [], '{"error":"Invalid deck contents"}')]);
        $this->expectException(GempRejectedException::class);
        $this->expectExceptionMessage('Invalid deck contents');
        $gemp->validate('fotr_block', '1_2|x');
    }

    public function testUnreachableGempIsUnavailable(): void
    {
        $gemp = $this->client([new ConnectException('refused', new Request('POST', 'deck/statsJson'))]);
        $this->expectException(GempUnavailableException::class);
        $gemp->validate('fotr_block', 'x');
    }

    public function testServerErrorIsUnavailable(): void
    {
        $gemp = $this->client([new Response(500, [], 'oops')]);
        $this->expectException(GempUnavailableException::class);
        $gemp->formats();
    }

    public function testSetsComeFromTheNumericSetFilters(): void
    {
        $this->assertSame(['0', '1', '9', '51', '101'], FormatsUpdater::setsFrom([
            ['filter' => '101', 'setFilters' => ['V1' => '101']],
            ['filter' => 'x', 'setFilters' => ['FotR' => '1', 'Promo' => '0', 'Reflections' => '9', 'FotR again' => '1']],
            ['filter' => '51', 'setFilters' => ['FotR errata' => '51']],
            ['filter' => 'pc_errata', 'setFilters' => ['Latest PC Errata' => 'pc_errata']],
        ]));
    }
}
