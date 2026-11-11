<?php

namespace App\Classes;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Markdown back from a comment's html (Ketura, 2026-11-11), for comments from before the
 * markdown was kept: to edit, copy or quote them. Enough for what comments hold (paragraphs,
 * line breaks, emphasis, links, card links, quotes, lists, code); anything else comes back as
 * its text.
 */
final class HtmlToMarkdown
{
    public static function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementsByTagName('div')->item(0);
        $md = $root ? self::blocks($root) : strip_tags($html);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $md));
    }

    private static function blocks(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                $out .= match ($tag) {
                    'p', 'div' => self::inline($child) . "\n\n",
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => str_repeat('#', (int) $tag[1]) . ' ' . self::inline($child) . "\n\n",
                    'blockquote' => implode("\n", array_map(fn ($l) => rtrim('> ' . $l), explode("\n", trim(self::blocks($child))))) . "\n\n",
                    'ul', 'ol' => self::listItems($child, $tag === 'ol') . "\n",
                    'pre' => "```\n" . rtrim($child->textContent) . "\n```\n\n",
                    'hr' => "---\n\n",
                    default => self::inline($child),
                };
            } elseif (trim((string) $child->textContent) !== '') {
                $out .= self::text($child);   // the space between blocks is none of the text's
            }
        }
        return $out;
    }

    private static function listItems(DOMElement $list, bool $ordered): string
    {
        $out = '';
        $n = 1;
        foreach ($list->childNodes as $item) {
            if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                $out .= ($ordered ? ($n++) . '. ' : '- ') . trim(self::inline($item)) . "\n";
            }
        }
        return $out;
    }

    private static function inline(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                $out .= self::text($child);
                continue;
            }
            $inner = self::inline($child);
            $out .= match (strtolower($child->tagName)) {
                'br' => "  \n",
                'strong', 'b' => '**' . $inner . '**',
                'em', 'i' => '*' . $inner . '*',
                'code' => '`' . $child->textContent . '`',
                'a' => self::link($child, $inner),
                'p', 'div' => $inner . "\n\n",
                'ul', 'ol' => "\n" . self::listItems($child, strtolower($child->tagName) === 'ol'),
                'blockquote' => "\n" . self::blocks($child),
                default => $inner,
            };
        }
        return $out;
    }

    private static function link(DOMElement $a, string $inner): string
    {
        if (str_contains(' ' . $a->getAttribute('class') . ' ', ' rb-cardlink ')) {
            return '[[' . $a->textContent . ']]';
        }
        $href = $a->getAttribute('href');
        if ($href === '' || $href === $a->textContent) {
            return $href !== '' ? $href : $inner;
        }
        return '[' . $inner . '](' . $href . ')';
    }

    private static function text(DOMNode $node): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) $node->textContent);
    }
}
