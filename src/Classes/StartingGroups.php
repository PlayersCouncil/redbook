<?php

namespace App\Classes;

/**
 * A deck's starting fellowships (Ketura, 2026-11-11): one or more groups of companions, each
 * with a name ("going first", "vs. Ringwraith"), as the deck's starting_fellowship holds them:
 * [{name, codes}]. Before 2026-11-11 it held one list of codes, which reads as one unnamed
 * group. A group with no name is called by its place: "Starting Fellowship", then
 * "Starting Fellowship 2", and so on (the views translate that).
 */
final class StartingGroups
{
    public const MAX_GROUPS = 10;
    public const MAX_NAME = 60;

    /**
     * Any stored or posted form, cleaned: codes as blueprint IDs, each once per group; names
     * trimmed and capped; groups with neither a name nor a companion dropped
     * @return array<array{name: string, codes: string[]}>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        // the one list of before
        if (array_is_list($raw) && !array_filter($raw, 'is_array')) {
            $raw = [['name' => '', 'codes' => $raw]];
        }
        $groups = [];
        foreach ($raw as $group) {
            if (!is_array($group)) {
                continue;
            }
            $codes = array_values(array_unique(array_filter(
                array_map('strval', (array) ($group['codes'] ?? $group['cards'] ?? [])),
                fn ($c) => preg_match('/^\d+_\d+$/', $c)
            )));
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($group['name'] ?? '')))), 0, self::MAX_NAME);
            if ($codes === [] && $name === '') {
                continue;
            }
            $groups[] = ['name' => $name, 'codes' => $codes];
            if (count($groups) === self::MAX_GROUPS) {
                break;
            }
        }
        return $groups;
    }

    /**
     * Only the companions keep() allows (the ones the deck holds); a group left with neither
     * name nor companion goes
     * @param callable(string): bool $keep
     */
    public static function filter(array $groups, callable $keep): array
    {
        return self::normalize(array_map(fn ($g) => ['name' => $g['name'], 'codes' => array_values(array_filter($g['codes'], $keep))], self::normalize($groups)));
    }

    /** @return string[] every companion that starts in any group, once */
    public static function union(array $groups): array
    {
        $codes = [];
        foreach (self::normalize($groups) as $group) {
            foreach ($group['codes'] as $code) {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }
}
