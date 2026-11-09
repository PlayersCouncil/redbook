<?php

namespace App\Services;

use App\Services\Gemp\DeckValidation;

/**
 * Only legal decks are published (Ketura, 2026-11-08; decision 0025, replacing 0023's
 * "never blocked"). The one exception is a deck fragment: at least 30 cards of one side
 * and none of the other, whose only problems are the ones a fragment can't help having:
 * no Ring-bearer, Ring, Map or full set of sites, too few cards, unequal sides. Any line
 * about a card itself (not legal in the format, too many copies, a wrong site) still
 * stops it, Ring-bearer, Ring and sites included.
 *
 * Gemp's problems are English sentences (DefaultLotroFormat), so the fragment's allowed
 * ones are matched by their wording until Gemp returns problem codes (0019).
 */
class PublishGate
{
    public const FRAGMENT_SIZE = 30;

    /** What a fragment is allowed to be missing, as Gemp words it */
    private const FRAGMENT_PROBLEMS = [
        "/^Deck doesn't have a Ring-bearer\.?$/",
        "/^Deck doesn't have a One Ring\.?$/",
        "/^Deck doesn't have a Map\b/",
        "/^Deck doesn't have sites\.?$/",
        '/^Deck has \d+ sites instead of 9\.?$/',
        '/^Deck contains below minimum number of cards: \d+<\d+\.?$/',
        '/^Deck contains different number of Shadow and Free peoples cards\.?$/',
    ];

    /**
     * @return array{ok: bool, kind: 'whole'|'fragment'|null, reason: 'illegal'|'unavailable'|'no_format'|null, format: string, problems: string[]}
     */
    public static function verdict(?DeckValidation $validation, ?string $format): array
    {
        $result = ['ok' => false, 'kind' => null, 'reason' => null, 'format' => $validation?->formatName ?: (string) $format, 'problems' => []];
        if (!$format) {
            return ['reason' => 'no_format'] + $result;
        }
        if ($validation === null) {
            // Gemp couldn't be reached: nothing says it's legal
            return ['reason' => 'unavailable'] + $result;
        }
        if ($validation->isValid()) {
            return ['ok' => true, 'kind' => 'whole'] + $result;
        }
        $problems = $validation->getRelevantProblems();
        if (self::isFragment($validation->freePeoplesCount, $validation->shadowCount, $problems)) {
            return ['ok' => true, 'kind' => 'fragment', 'problems' => $problems] + $result;
        }
        return ['reason' => 'illegal', 'problems' => $problems] + $result;
    }

    /** @param string[] $problems */
    public static function isFragment(int $freePeoples, int $shadow, array $problems): bool
    {
        $oneSide = ($freePeoples >= self::FRAGMENT_SIZE && $shadow === 0) || ($shadow >= self::FRAGMENT_SIZE && $freePeoples === 0);
        if (!$oneSide) {
            return false;
        }
        foreach ($problems as $problem) {
            if (!self::allowedInFragment(trim($problem))) {
                return false;
            }
        }
        return true;
    }

    private static function allowedInFragment(string $problem): bool
    {
        foreach (self::FRAGMENT_PROBLEMS as $pattern) {
            if (preg_match($pattern, $problem)) {
                return true;
            }
        }
        return false;
    }
}
