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
 *
 * Gemp's two deck pages, a shared deck (/share/deck?id=…) and a library deck
 * (/gemp-lotr-server/deck/libraryHtml?deckName=…), are the same HTML: a card list whose
 * names carry the wiki image of the exact printing. That image gives each line its card's
 * ID ("Northern Ithilien (57_359)"), so the PC errata comes in as the errata.
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
        // Gemp's card names: <span class="tooltip">Name<span><img src="…/LOTR-EN07E359.1_card.jpg"></span></span>
        $html = preg_replace_callback(
            '#<span class="tooltip">([^<]*)<span>\s*<img[^>]*src="[^"]*/LOTR-EN(\d{2}|V\d)[A-Z](\d{3})\.(\d+)_card\.[a-z]+"[^>]*>\s*</span>\s*</span>#i',
            fn ($m) => trim($m[1]) . ' (' . self::wikiImageCode($m[2], $m[3], (int) $m[4]) . ')',
            $html,
        );
        $html = preg_replace('#<\s*(br|/p|/div|/li|/tr|/h\d|/dt|/dd)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen');
        return implode("\n", $lines);
    }

    /**
     * The card ID a wiki image names (Ketura, 2026-11-08): LOTR-EN09S033.0 is 9_33 and
     * V2S076.0 is 102_76. The number after the dot is the revision: .0 the original printing,
     * .1 the first revision, and so on. Not every revision is a PC errata (Decipher issued
     * some), but for a deck on Gemp it's the likely one, so a revision above .0 is tried as
     * the errata set 50 above (07E359.1 is 57_359); the letter before the number isn't relied
     * on. V-sets have no errata sets, they're updated in place, so their revision doesn't
     * change the ID. An ID Red Book doesn't have falls back to the card's name
     * (DeckImportService), which picks the Decipher card.
     */
    public static function wikiImageCode(string $set, string $number, int $revision = 0): string
    {
        $set = strtoupper($set);
        if (str_starts_with($set, 'V')) {
            return (100 + (int) substr($set, 1)) . '_' . (int) $number;
        }
        return ((int) $set + ($revision > 0 ? 50 : 0)) . '_' . (int) $number;
    }
}
