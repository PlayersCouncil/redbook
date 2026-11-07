<?php

namespace App\Services;

/**
 * Game symbols in markdown (Ketura, 2026-11-07), turned into icons:
 *
 *   [Moria] [Gondor] [Uruk-hai] ...   a culture's symbol, by name, any case
 *   [Towers] [King]                   the Towers and King block symbols
 *   1T..9T  1K..9K                    a number then the block symbol; [1T] and 1[T] too
 *   (0)..(9) (X)                      a twilight cost; other values stay as text
 *
 * Not inside code, and not a markdown link ([Moria](...)). Each icon carries its name for
 * a tooltip (data-tip) and the original text for screen readers and copying.
 * assets/js/app.symbols.js does the same in the browser: change the two together.
 */
class MarkdownSymbols
{
    /** culture names as written, by the key they're matched on (lower case, letters only) */
    public const CULTURES = [
        'dunland' => ['dunland', 'Dunland'], 'dwarven' => ['dwarven', 'Dwarven'], 'elven' => ['elven', 'Elven'],
        'gandalf' => ['gandalf', 'Gandalf'], 'gollum' => ['gollum', 'Gollum'], 'gondor' => ['gondor', 'Gondor'],
        'isengard' => ['isengard', 'Isengard'], 'men' => ['men', 'Men'], 'moria' => ['moria', 'Moria'],
        'orc' => ['orc', 'Orc'], 'raider' => ['raider', 'Raider'], 'ringwraith' => ['ringwraith', 'Ringwraith'],
        'rohan' => ['rohan', 'Rohan'], 'sauron' => ['sauron', 'Sauron'], 'shire' => ['shire', 'Shire'],
        'theonering' => ['the-one-ring', 'The One Ring'], 'urukhai' => ['uruk-hai', 'Uruk-hai'],
    ];

    public const BLOCKS = ['t' => ['towers', 'Towers Block'], 'k' => ['king', 'King Block']];

    private const CODE = '/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]*`)/';

    /**
     * Swaps each symbol outside code for a token markdown leaves alone.
     * @return array{0: string, 1: array<string, string>} the text, and each token's HTML
     */
    public static function protect(string $markdown): array
    {
        $tokens = [];
        $keep = function (string $html) use (&$tokens): string {
            $token = 'rbsym' . count($tokens) . 'x';
            $tokens[$token] = $html;
            return $token;
        };
        $parts = preg_split(self::CODE, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            // [Name], [Towers], [King], [1T]
            $part = preg_replace_callback('/\[([A-Za-z][A-Za-z \-\']{0,20}|[1-9][TtKk])\](?![\(\[:])/', function (array $m) use ($keep) {
                $html = self::bracketed($m[1], $m[0]);
                return $html === null ? $m[0] : $keep($html);
            }, $part);
            // 1[T]
            $part = preg_replace_callback('/(?<![\w\]])([1-9])\[([TtKk])\]/', fn (array $m) => $keep($m[1] . self::block(strtolower($m[2]), $m[0])), $part);
            // 1T, 9K
            $part = preg_replace_callback('/(?<![\w\[])([1-9])([TK])(?![\w\]])/', fn (array $m) => $keep($m[1] . self::block(strtolower($m[2]), $m[0])), $part);
            // (3), (X)
            $part = preg_replace_callback('/(?<!\])\(([0-9Xx])\)/', fn (array $m) => $keep(self::twilight(strtolower($m[1]), $m[0])), $part);
            $parts[$i] = $part;
        }
        return [implode('', $parts), $tokens];
    }

    /** Puts the icons in place of the tokens */
    public static function restore(string $html, array $tokens): string
    {
        if (!$tokens) {
            return $html;
        }
        // longest first, so rbsym1x never eats part of rbsym10x
        uksort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));
        return strtr($html, $tokens);
    }

    private static function bracketed(string $inner, string $original): ?string
    {
        if (preg_match('/^([1-9])([tk])$/i', $inner, $m)) {
            return $m[1] . self::block(strtolower($m[2]), $original);
        }
        $key = preg_replace('/[^a-z]/', '', strtolower($inner));
        if (isset(self::CULTURES[$key])) {
            [$file, $name] = self::CULTURES[$key];
            return self::icon('rb-sym-' . $file, $name, $original);
        }
        if (in_array($key, ['towers', 'tower'], true)) {
            return self::block('t', $original);
        }
        return $key === 'king' ? self::block('k', $original) : null;
    }

    private static function block(string $letter, string $original): string
    {
        [$file, $name] = self::BLOCKS[$letter];
        return self::icon('rb-sym-' . $file, $name, $original);
    }

    private static function twilight(string $value, string $original): string
    {
        return self::icon('rb-sym-twilight rb-sym-twilight-' . $value, strtoupper($value) . ' Twilight', $original);
    }

    private static function icon(string $class, string $name, string $original): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        return '<span class="rb-sym" data-tip="' . $e($name) . '"><span class="rb-sym-icon ' . $class . '"></span>'
            . '<span class="sr-only">' . $e($original) . '</span></span>';
    }
}
