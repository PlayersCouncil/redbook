<?php

namespace App\Services;

use App\Entity\Format;
use Doctrine\DBAL\Connection;

/**
 * A card's versions (Ketura, 2026-11-08), for decks that name a card any version will do for
 * ("any Frodo", "Legolas (any)"):
 * - for most cards, every card with the same title, compared folded as the archive does
 *   (decipher-map's cardres.norm: accents dropped, lower case, apostrophes dropped, "&" as
 *   "and", other runs of non-letters one space), so "Éowyn" and "Eowyn" are one title;
 * - for a site, every site of its path with its number ("any site 3"), and for a Shadows
 *   site every Shadows site ("any site").
 * Reprints of a card, same title and subtitle, are one version.
 *
 * When a single card is needed (Gemp's deck string, validation, the stats), a generic slot
 * stands for its stand-in: the version it was made from when that's legal in the format,
 * else the format's first legal version.
 */
class CardVersions
{
    private const SITE_BLOCK_BY_SET = [
        '1' => 'Fellowship', '2' => 'Fellowship', '3' => 'Fellowship', '101' => 'Fellowship',
        '4' => 'Towers', '5' => 'Towers', '6' => 'Towers', '102' => 'Towers',
        '7' => 'King', '8' => 'King', '9' => 'King', '10' => 'King', '103' => 'King',
    ];

    /** @var array<string, array{code: string, name: string, subtitle: ?string, set: string, group: string}>|null */
    private ?array $cards = null;
    /** @var array<string, string[]> group => codes, in natural order */
    private array $groups = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** How a card any version will do for reads: "Legolas (any)", "Any site 3", "Any site" */
    public static function genericLabel(\App\Entity\Card $card): string
    {
        if ($card->getType()->getCode() === 'site') {
            return $card->getSiteNumber() ? 'Any site ' . $card->getSiteNumber() : 'Any site';
        }
        return $card->getName() . ' (any)';
    }

    public static function fold(?string $text): string
    {
        $text = (string) $text;
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KD) ?: $text;
        }
        $text = preg_replace('/\p{Mn}+/u', '', $text);
        $text = mb_strtolower($text);
        $text = str_replace(["'", '’'], '', $text);
        $text = str_replace('&', ' and ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim($text);
    }

    /** The cards with the same versions as this one, itself included; empty for an unknown card */
    public function members(string $code): array
    {
        $this->load();
        $card = $this->cards[$code] ?? null;
        return $card ? $this->groups[$card['group']] : [];
    }

    /**
     * The version a title's "any:" card is kept as (any:Frodo): its earliest printing outside
     * set 0, a grab-bag of promos across many formats (Ketura, 2026-11-08), legal in the format
     * when one is given
     */
    public function byTitle(string $title, ?Format $format = null): ?string
    {
        $this->load();
        $first = $this->groups['title|' . self::fold($title)][0] ?? null;
        return $first === null ? null : $this->exemplar($first, $format);
    }

    /** The lowest non-zero set's version legal in the format, else outside set 0, else the card itself */
    public function exemplar(string $code, ?Format $format): string
    {
        $members = $this->members($code);
        $outside = array_values(array_filter($members, fn ($m) => ($this->cards[$m]['set'] ?? '0') !== '0'));
        foreach ($outside as $member) {
            if ($this->isLegal($member, $format)) {
                return $member;
            }
        }
        return $outside[0] ?? $code;
    }

    /** Whether a card can be made generic in the format: some other version of it is legal there */
    public function canBeGeneric(string $code, ?Format $format): bool
    {
        return count($this->versions($code, $format)) > 1;
    }

    /** One code per version legal in the format (all versions without a format) */
    public function versions(string $code, ?Format $format): array
    {
        $this->load();
        $seen = [];
        foreach ($this->members($code) as $member) {
            if (!$this->isLegal($member, $format)) {
                continue;
            }
            $card = $this->cards[$member];
            $version = str_starts_with($card['group'], 'site|') ? self::fold($card['name']) : self::fold($card['name']) . '|' . self::fold($card['subtitle']);
            $seen[$version] ??= $member;
        }
        return array_values($seen);
    }

    /** The card a generic slot stands for where one card is needed */
    public function standIn(string $code, ?Format $format): string
    {
        if ($this->isLegal($code, $format)) {
            return $code;
        }
        // the lowest non-zero set first: set 0 is promos from many formats
        $members = $this->members($code);
        usort($members, fn ($a, $b) => (($this->cards[$a]['set'] ?? '0') === '0') <=> (($this->cards[$b]['set'] ?? '0') === '0'));
        foreach ($members as $member) {
            if ($this->isLegal($member, $format)) {
                return $member;
            }
        }
        return $code;
    }

    private function isLegal(string $code, ?Format $format): bool
    {
        $sets = $format?->getSets() ?? [];
        return !$sets || in_array($this->cards[$code]['set'] ?? null, array_map('strval', $sets), true);
    }

    private function load(): void
    {
        if ($this->cards !== null) {
            return;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT c.code, c.name, c.subtitle, c.site_number, c.site_block, t.code AS type, p.code AS pack, e.code AS errata_of
             FROM card c JOIN type t ON t.id = c.type_id JOIN pack p ON p.id = c.pack_id LEFT JOIN card e ON e.id = c.errata_of_id'
        );
        $this->cards = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'site') {
                $set = $row['errata_of'] ? explode('_', $row['errata_of'])[0] : $row['pack'];
                $block = $row['site_block'] ?: (self::SITE_BLOCK_BY_SET[$set] ?? '');
                $group = $block === 'Shadows' || !(int) $row['site_number'] ? 'site|Shadows' : 'site|' . $block . '|' . (int) $row['site_number'];
            } else {
                $group = 'title|' . self::fold($row['name']);
            }
            $this->cards[$row['code']] = ['code' => $row['code'], 'name' => $row['name'], 'subtitle' => $row['subtitle'], 'set' => (string) $row['pack'], 'group' => $group];
            $this->groups[$group][] = $row['code'];
        }
        foreach ($this->groups as &$codes) {
            sort($codes, SORT_NATURAL);
        }
    }
}
