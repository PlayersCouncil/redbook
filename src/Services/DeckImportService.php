<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Reads decks pasted or uploaded as text. Accepts, per deck:
 *  - a Gemp deck string: ringBearer|ring|site,...|card,...[|map]
 *  - a card list, one card per line: "2x Legolas, Greenleaf", "2 Legolas, Greenleaf (1R50)",
 *    "Legolas, Greenleaf (1_50)", with optional section headings (Ring-bearer, Ring,
 *    Adventure deck / Sites, Free Peoples, Shadow, Map). Red Book's own text export is
 *    one of these.
 * Several decks are separated by a line of three or more "=". The first line of a
 * card list that isn't a card or a heading is the deck's name.
 */
class DeckImportService
{
    protected const DECKS_DELIMITER_REGEXP = '/^\s*={3,}\s*$/m';

    /** "2x Name (info)", "2 Name", "Name (info)", "Name x2" */
    protected const CARD_LINE_REGEXP = '/^(?:(\d+)\s*x?\s+)?(.+?)(?:\s+x(\d+))?(?:\s*\(([^)]+)\))?$/iu';

    protected const HEADINGS = [
        'ring-bearer' => DeckContent::ROLE_RING_BEARER, 'ring bearer' => DeckContent::ROLE_RING_BEARER,
        'ring' => DeckContent::ROLE_RING, 'the one ring' => DeckContent::ROLE_RING,
        'sites' => DeckContent::ROLE_SITE, 'adventure deck' => DeckContent::ROLE_SITE, 'adventure path' => DeckContent::ROLE_SITE,
        'draw deck' => DeckContent::ROLE_DRAW, 'free peoples' => DeckContent::ROLE_DRAW, 'shadow' => DeckContent::ROLE_DRAW,
        'map' => DeckContent::ROLE_MAP,
    ];

    /** @var array<string, Card>|null */
    private ?array $byCode = null;
    /** @var array<string, Card[]> */
    private array $byName = [];
    /** @var array<string, Card> */
    private array $byCollInfo = [];

    public function __construct(protected EntityManagerInterface $em, protected ?CardVersions $versions = null)
    {
    }

    /** For tests: the cards to resolve against instead of the database */
    public function setCards(array $cards): void
    {
        $this->byCode = [];
        $this->byName = [];
        $this->byCollInfo = [];
        foreach ($cards as $card) {
            $this->index($card);
        }
    }

    /**
     * @return array{decks: array<array{name: string, content: array<string, int>, description: string, warnings: string[]}>, errors: string[]}
     */
    public function parseTextImport(string $text): array
    {
        $result = ['decks' => [], 'errors' => []];
        foreach (preg_split(self::DECKS_DELIMITER_REGEXP, trim($text), -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $chunk)), 'strlen'));
            if (!$lines) {
                continue;
            }
            try {
                $result['decks'][] = $this->parseOne($lines);
            } catch (InvalidArgumentException $e) {
                $result['errors'][] = $e->getMessage();
            }
        }
        return $result;
    }

    protected function parseOne(array $lines): array
    {
        $this->load();
        $deck = ['name' => 'Imported deck', 'content' => [], 'description' => '', 'warnings' => []];

        // a Gemp deck string, perhaps after a name line
        foreach ($lines as $i => $line) {
            $line = preg_replace('/^gemp:\s*/i', '', $line);
            if (substr_count($line, '|') >= 3 && preg_match('/^[\d_,|*T\s]+$/', $line)) {
                if ($i > 0) {
                    $deck['name'] = $lines[0];
                }
                $deck['content'] = DeckContent::fromGempString($line);
                $unknown = array_filter(array_keys($deck['content']), fn ($key) => !isset($this->byCode[DeckContent::parseKey($key)[0]]));
                foreach ($unknown as $key) {
                    $deck['warnings'][] = 'Unknown card ' . DeckContent::parseKey($key)[0];
                    unset($deck['content'][$key]);
                }
                return $deck;
            }
        }

        $role = null;
        $named = false;
        foreach ($lines as $line) {
            if (preg_match('/^(format|version|tags):/i', $line)) {
                continue;
            }
            $heading = $this->heading($line);
            if ($heading !== null) {
                [$role, $rest] = $heading;
                if ($rest === '') {
                    continue;
                }
                $line = $rest;
            }
            $card = $this->parseCardLine($line);
            if ($card === null) {
                if (!$named && !$deck['content']) {
                    $deck['name'] = $line;
                    $named = true;
                } else {
                    $deck['warnings'][] = "Couldn't find a card for \"$line\"";
                }
                continue;
            }
            [$found, $quantity, $generic] = $card + [2 => false];
            $key = DeckContent::key($found->getCode(), $role === DeckContent::ROLE_RING_BEARER && !$this->hasRingBearer($deck['content'])
                ? DeckContent::ROLE_RING_BEARER : DeckContent::ROLE_DRAW, $generic);
            $deck['content'][$key] = str_starts_with($key, DeckContent::RING_BEARER_PREFIX) ? 1 : ($deck['content'][$key] ?? 0) + $quantity;
        }
        if (!$deck['content']) {
            throw new InvalidArgumentException(sprintf('No cards found in "%s"', $deck['name']));
        }
        return $deck;
    }

    private function hasRingBearer(array $content): bool
    {
        foreach (array_keys($content) as $key) {
            if (str_starts_with($key, DeckContent::RING_BEARER_PREFIX)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: string, 1: string}|null the role a heading starts, and anything after "Heading:" */
    private function heading(string $line): ?array
    {
        if (!preg_match('/^[#*\s]*([A-Za-z\- ]+?)\s*(?:\(\d+\))?\s*(?::\s*(.*))?$/u', $line, $m)) {
            return null;
        }
        $role = self::HEADINGS[strtolower(trim($m[1]))] ?? null;
        if ($role === null) {
            return null;
        }
        // "Ring-bearer: Frodo, Son of Drogo" or a bare heading line
        return [$role, trim($m[2] ?? '')];
    }

    /** @return array{0: Card, 1: int, 2?: bool}|null the card, its copies, and whether any version will do */
    private function parseCardLine(string $line): ?array
    {
        $line = trim(preg_replace('/^[-*•·\s]+/u', '', $line));
        if (!preg_match(self::CARD_LINE_REGEXP, $line, $m)) {
            return null;
        }
        $quantity = (int) ($m[1] ?: ($m[3] ?? 0) ?: 1);
        $name = trim($m[2]);
        $info = trim($m[4] ?? '');

        // "Legolas (any)", as Red Book's own export writes it (Ketura, 2026-11-08)
        if (preg_match('/^(.+?)\s*\(any\)$/i', $name . ($info !== '' ? " ($info)" : ''), $any)) {
            $code = $this->versions?->byTitle(trim($any[1], " \t•·*"));
            if ($code !== null && isset($this->byCode[$code])) {
                return [$this->byCode[$code], $quantity, true];
            }
        }

        if ($info !== '') {
            if (preg_match('/^\d+_\d+$/', $info) && isset($this->byCode[$info])) {
                return [$this->byCode[$info], $quantity];
            }
            $collInfo = strtoupper(str_replace(' ', '', $info));
            if (isset($this->byCollInfo[$collInfo])) {
                return [$this->byCollInfo[$collInfo], $quantity];
            }
        }
        if (preg_match('/^\d+_\d+$/', $name) && isset($this->byCode[$name])) {
            return [$this->byCode[$name], $quantity];
        }
        $candidates = $this->byName[self::normalize($name)] ?? [];
        if (!$candidates) {
            return null;
        }
        // the Decipher card over its PC errata, the earliest printing over reprints
        usort($candidates, fn (Card $a, Card $b) => [$a->isErrata(), $a->getPack()->getPosition(), $a->getPosition()]
            <=> [$b->isErrata(), $b->getPack()->getPosition(), $b->getPosition()]);
        return [$candidates[0], $quantity];
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/^[•·*\s]+/u', '', $name)));
        $name = strtr($name, ['’' => "'", '‘' => "'", 'é' => 'e', 'ó' => 'o', 'í' => 'i', 'û' => 'u', 'ú' => 'u', 'â' => 'a', 'ë' => 'e']);
        return preg_replace('/\s+/', ' ', preg_replace('/\s*,\s*/', ', ', $name));
    }

    private function load(): void
    {
        if ($this->byCode !== null) {
            return;
        }
        $this->byCode = [];
        foreach ($this->em->getRepository(Card::class)->findAll() as $card) {
            $this->index($card);
        }
    }

    private function index(Card $card): void
    {
        $this->byCode[$card->getCode()] = $card;
        $this->byName[self::normalize($card->getFullName())][] = $card;
        if ($card->getCollInfo() && !$card->isErrata()) {
            $this->byCollInfo[strtoupper(str_replace(' ', '', $card->getCollInfo()))] ??= $card;
        }
    }
}
