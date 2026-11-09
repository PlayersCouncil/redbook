<?php

namespace App\Classes;

use InvalidArgumentException;

/**
 * A LOTR deck's content as Red Book passes it around: a map of key => quantity,
 * where the key is the card's blueprint ID, except for the Ring-bearer, which is
 * "rb:" + its blueprint ID (the same companion may also be in the draw deck).
 * Every other card's part of the deck follows from its type (phase3/03):
 *
 *   {"rb:1_290": 1, "1_2": 1, "1_319": 1, ..., "1_50": 3}
 *
 * A card any version will do for (Ketura, 2026-11-08: "any Frodo", "Legolas (any)") is
 * "any:" + the blueprint ID of the version it was made from, after the Ring-bearer's "rb:"
 * if it's that: "rb:any:1_290", "any:1_50". Which cards count as its versions is
 * App\Services\CardVersions.
 *
 * Gemp's deck string is ringBearer|ring|site,site,...|card,card,...[|map], one
 * entry per copy (decision 0019).
 */
final class DeckContent
{
    public const ROLE_RING_BEARER = 'ring_bearer';
    public const ROLE_RING = 'ring';
    public const ROLE_SITE = 'site';
    public const ROLE_DRAW = 'draw';
    public const ROLE_MAP = 'map';

    /** Deck order: the order of the deckbuilder's sections and of Gemp's deck string */
    public const ROLES = [self::ROLE_RING_BEARER, self::ROLE_RING, self::ROLE_SITE, self::ROLE_DRAW, self::ROLE_MAP];

    public const RING_BEARER_PREFIX = 'rb:';
    public const ANY_PREFIX = 'any:';

    public static function key(string $code, string $role, bool $generic = false): string
    {
        return ($role === self::ROLE_RING_BEARER ? self::RING_BEARER_PREFIX : '') . ($generic ? self::ANY_PREFIX : '') . $code;
    }

    /** @return array{0: string, 1: bool, 2: bool} blueprint ID, whether the key is the Ring-bearer, and whether any version will do */
    public static function parseKey(string $key): array
    {
        $ringBearer = str_starts_with($key, self::RING_BEARER_PREFIX);
        if ($ringBearer) {
            $key = substr($key, strlen(self::RING_BEARER_PREFIX));
        }
        $generic = str_starts_with($key, self::ANY_PREFIX);
        if ($generic) {
            $key = substr($key, strlen(self::ANY_PREFIX));
        }
        return [$key, $ringBearer, $generic];
    }

    public static function roleForType(?string $typeCode): string
    {
        return match ($typeCode) {
            'the_one_ring' => self::ROLE_RING,
            'site' => self::ROLE_SITE,
            'map' => self::ROLE_MAP,
            default => self::ROLE_DRAW,
        };
    }

    /**
     * The Gemp deck string for slots given as [blueprint ID, role, quantity] triples.
     * Gemp reports a missing Ring-bearer or Ring itself, so empty parts stay empty.
     *
     * @param iterable<array{0: string, 1: string, 2: int}> $slots
     */
    public static function toGempString(iterable $slots): string
    {
        $parts = array_fill_keys(self::ROLES, []);
        foreach ($slots as [$code, $role, $quantity]) {
            for ($i = 0; $i < $quantity; $i++) {
                $parts[$role][] = $code;
            }
        }
        foreach ($parts as &$codes) {
            sort($codes, SORT_NATURAL);
        }
        unset($codes);
        $string = implode('|', [
            $parts[self::ROLE_RING_BEARER][0] ?? '',
            $parts[self::ROLE_RING][0] ?? '',
            implode(',', $parts[self::ROLE_SITE]),
            implode(',', $parts[self::ROLE_DRAW]),
        ]);
        if ($parts[self::ROLE_MAP]) {
            $string .= '|' . $parts[self::ROLE_MAP][0];
        }
        return $string;
    }

    /**
     * Reads a Gemp deck string into a content map. Roles come from the string's
     * positions, so a card in the wrong part (a non-site among the sites) keeps
     * the role Gemp's string gave it only for the Ring-bearer; everything else
     * is placed by type when saved, and Gemp's validation reports the mistake.
     *
     * @return array<string, int>
     */
    public static function fromGempString(string $deck): array
    {
        $parts = explode('|', trim($deck));
        if (count($parts) < 4 || count($parts) > 5) {
            throw new InvalidArgumentException('A Gemp deck has four or five parts separated by "|"');
        }
        $content = [];
        $add = function (string $key) use (&$content) {
            $content[$key] = ($content[$key] ?? 0) + 1;
        };
        foreach ($parts as $i => $part) {
            foreach (array_filter(array_map('trim', explode(',', $part)), 'strlen') as $code) {
                if (!preg_match('/^\d+_\d+[*T]*$/', $code)) {
                    throw new InvalidArgumentException("\"$code\" isn't a Gemp blueprint ID");
                }
                $code = rtrim($code, '*T'); // foil and tengwar printings are the same card
                $add($i === 0 ? self::RING_BEARER_PREFIX . $code : $code);
            }
        }
        return $content;
    }
}
