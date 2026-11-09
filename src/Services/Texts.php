<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Parsedown;

/**
 * Class Texts
 * @package App\Services
 */
class Texts
{
    protected HTMLPurifier $purifier;

    protected Parsedown $parsedown;

    /**
     * Texts constructor.
     * @param string $kernelProjectDirectory
     */
    public function __construct(string $kernelProjectDirectory, private readonly ?CardLinker $cardLinker = null)
    {
        $config = HTMLPurifier_Config::create(array('Cache.SerializerPath' => $kernelProjectDirectory));
        // bump when the definition changes, or the serializer cache keeps the old one
        $config->set('HTML.DefinitionID', 'redbook-texts');
        $config->set('HTML.DefinitionRev', 3);
        // null when the serializer cache already has this revision
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('a', 'data-code', 'Text');
            // a [[card.1]] link hovers that revision's image
            $def->addAttribute('a', 'data-image', 'URI');
            // a game symbol's name, for its tooltip (MarkdownSymbols)
            $def->addAttribute('span', 'data-tip', 'Text');
        }
        $this->purifier = new HTMLPurifier($config);
        $this->parsedown = new Parsedown();
    }

    /**
     * Returns a substring of $string that is $max_length length max and doesn't split
     * a word or a html tag
     *
     * @param string $string
     * @param int $max_length
     * @return string
     */
    public function truncate($string, $max_length)
    {
        $response = '';
        $token = '';

        $string = preg_replace('/\s+/', ' ', $string);

        while (strlen($token . $string) > 0 && strlen($response . $token) < $max_length) {
            $response = $response . $token;
            $matches = [];

            if (preg_match('/^(<.+?>)(.*)/', $string, $matches)) {
                $token = $matches[1];
                $string = $matches[2];
            } elseif (preg_match('/^([^\s]+\s*)(.*)/', $string, $matches)) {
                $token = $matches[1];
                $string = $matches[2];
            } else {
                $token = $string;
                $string = '';
            }
        }
        if (strlen($token) > 0) {
            $response = $response . '[&hellip;]';
        }

        return $response;
    }

    /**
     * A comment as it is stored (Ketura, 2026-11-09, shared by decklist and library comments):
     * bare web addresses made into links, then markdown
     */
    public function comment(string $text): string
    {
        $text = (string) preg_replace(
            '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)'
            . '(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))'
            . '(?::\d+)?)(?:[^\s]*)?%iu',
            '[$1]($0)',
            $text
        );
        return $this->markdown($text);
    }

    /**
     * Returns the processed version of a markdown text
     * @param string $string
     * @return string
     */
    public function markdown($string)
    {
        // [[card]] links (Ketura, 2026-11-03) and game symbols (2026-11-07): kept out of
        // markdown's way, then put back
        $links = [];
        if ($this->cardLinker) {
            [$string, $links] = $this->cardLinker->protect((string) $string);
        }
        [$string, $symbols] = MarkdownSymbols::protect((string) $string);
        $html = MarkdownSymbols::restore($this->imgResponsive($this->transform($string)), $symbols);
        return $this->purify($this->cardLinker ? $this->cardLinker->restore($html, $links) : $html);
    }

    /**
     * removes any dangerous code from a HTML string
     * @param string $string
     * @return string
     */
    public function purify($string)
    {
        return $this->purifier->purify($string);
    }

    /**
     * turns a Markdown string into a HTML string
     * @param string $string
     * @return string
     */
    public function transform($string)
    {
        return $this->parsedown->text($string);
    }

    /**
     * adds class="img-responsive" to every <img> tag
     * @param string $string
     * @return string
     */
    public function imgResponsive($string)
    {
        return preg_replace('/<img/', '<img class="img-responsive"', $string);
    }

    /**
     * Transforms the string into a valid filename, lower-case, no spaces, pure ASCII, etc.
     * @param string $filename
     * @return string
     */
    public function slugify($filename)
    {
        $filename = mb_ereg_replace('[^\w\-]', '-', $filename);
        $filename = iconv('utf-8', 'us-ascii//TRANSLIT', $filename);
        $filename = preg_replace('/[^\w\-]/', '', $filename);
        $filename = preg_replace('/-+/', '-', $filename);
        $filename = trim($filename, '-');
        $filename = strtolower($filename);
        return $filename;
    }
}
