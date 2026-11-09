<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import from Gemp (Ketura, 2026-11-08): the text of a deck Gemp shares at an address, for
 * DeckImportService. Only Gemp's own hosts are fetched (the configured GEMP_URL's, and the
 * PC's lotrtcgpc.net), so the field can't be used to make Red Book fetch anything else. A page
 * comes back as its text, one line per block; a Gemp deck string in it is used as it is.
 */
class GempDeckFetcher
{
    private const MAX_BYTES = 1048576;

    public function __construct(
        #[Autowire('%env(GEMP_URL)%')] private readonly string $gempUrl,
        private ?Client $http = null,
    ) {
    }

    public function fetch(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '' || !$this->allowed($host)) {
            throw new InvalidArgumentException('not a Gemp address');
        }
        $this->http ??= new Client(['connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => ['max' => 3],
            'headers' => ['User-Agent' => 'RedBook (+https://github.com/PlayersCouncil/redbook)']]);
        try {
            $response = $this->http->request('GET', trim($url), ['stream' => true]);
        } catch (GuzzleException $e) {
            throw new InvalidArgumentException('Gemp did not answer');
        }
        $body = $response->getBody()->read(self::MAX_BYTES);
        $type = strtolower($response->getHeaderLine('Content-Type'));

        // a deck string anywhere in it: ringBearer|ring|sites|cards
        if (preg_match('/\d+_\d+\*?\|\d+_\d+\*?\|[\d_,*]*\|[\d_,*]+(?:\|\d+_\d+)?/', $body, $m)) {
            return str_replace('*', '', $m[0]);
        }
        if (str_contains($type, 'html') || preg_match('/<\s*(html|body|div|br)\b/i', $body)) {
            return self::htmlToText($body);
        }
        return $body;
    }

    private function allowed(string $host): bool
    {
        $gemp = strtolower((string) parse_url($this->gempUrl, PHP_URL_HOST));
        return ($gemp !== '' && $host === $gemp) || $host === 'lotrtcgpc.net' || str_ends_with($host, '.lotrtcgpc.net');
    }

    /** A page's text, one line per block element */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<\s*(br|/p|/div|/li|/tr|/h\d|/dt|/dd)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen');
        return implode("\n", $lines);
    }
}
