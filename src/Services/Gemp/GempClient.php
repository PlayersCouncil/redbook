<?php

namespace App\Services\Gemp;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gemp's public JSON endpoints (decision 0019). Red Book's backend is the only
 * caller; the browser never talks to Gemp.
 *
 * Deck contents use Gemp's deck string: ringBearer|ring|site,site,...|card,card,...[|map],
 * one entry per copy.
 */
class GempClient
{
    private ClientInterface $http;

    public function __construct(
        #[Autowire('%env(GEMP_URL)%')] string $baseUrl,
        private readonly LoggerInterface $logger,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => 10,
            'headers' => ['User-Agent' => 'RedBook (+https://github.com/PlayersCouncil/redbook)'],
        ]);
    }

    /** POST /deck/formats: every format, keyed by code (plus HallTimers, unused) */
    public function formats(): array
    {
        return $this->request('POST', 'deck/formats')['Formats'] ?? [];
    }

    /** GET /hall/formats/json: the hall formats with their card lists (banned, restricted, ...) */
    public function hallFormats(): array
    {
        return $this->request('GET', 'hall/formats/json')['formats'] ?? [];
    }

    /** POST /deck/statsJson: every problem with the deck in the format */
    public function validate(string $formatCode, string $deckContents): DeckValidation
    {
        return DeckValidation::fromGemp($this->request('POST', 'deck/statsJson', [
            'targetFormat' => $formatCode,
            'deckContents' => $deckContents,
        ]));
    }

    /**
     * POST /deck/convertJson: the deck with each card swapped for the format's PC
     * errata where there is one. Returns Gemp's answer (ringBearer, ring, sites,
     * drawDeck, map, deckContents).
     */
    public function convertToErrata(string $formatCode, string $deckContents): array
    {
        return $this->request('POST', 'deck/convertJson', [
            'targetFormat' => $formatCode,
            'deckContents' => $deckContents,
        ]);
    }

    private function request(string $method, string $path, ?array $form = null): array
    {
        try {
            $response = $this->http->request($method, $path, $form === null ? [] : ['form_params' => $form]);
        } catch (ClientException $e) {
            // Gemp refusing what it was sent answers with its {"error": ...}. Any other 4xx (a
            // 404 from a Gemp without these endpoints, say) is Gemp not answering, not a verdict
            // on the deck (Ketura, 2026-11-09: a whole import was marked invalid by a 404)
            $body = json_decode((string) $e->getResponse()->getBody(), true);
            if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
                throw new GempRejectedException($body['error'], 0, $e);
            }
            $this->logger->warning('Gemp unavailable: {message}', ['message' => $e->getMessage(), 'path' => $path]);
            throw new GempUnavailableException($e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            $this->logger->warning('Gemp unavailable: {message}', ['message' => $e->getMessage(), 'path' => $path]);
            throw new GempUnavailableException($e->getMessage(), 0, $e);
        }
        $json = json_decode((string) $response->getBody(), true);
        if (!is_array($json)) {
            throw new GempUnavailableException("Gemp's answer to $path wasn't JSON");
        }
        return $json;
    }
}
