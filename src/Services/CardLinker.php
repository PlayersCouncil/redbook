<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\CardVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * [[card]] links in markdown (Ketura, 2026-11-03). Any of these finds Aragorn, Heir to
 * the White City (3_38):
 *
 *   [[Aragorn, Heir to the White City]]  the full title
 *   [[Aragorn HTTWC]]                    the name and the subtitle's initials
 *   [[Heir to the White City]]           the subtitle
 *   [[HTTWC]]                            the subtitle's initials
 *   [[3_38]]  [[3R38]]                   the blueprint or the collector's number
 *   [[3_38.1]]                           revision 1 as the wiki numbers it (here the
 *                                        first PC errata), on the card's page
 *   [[HTTWC|the king]]                   any of these, with other text shown
 *
 * A bare name ([[Goblin Runner]]) works when one card has it. Names find Decipher and
 * V-set cards, not PC errata blueprints, which [[53_38]] or [[3_38.1]] reach. Reprints
 * with the same title resolve to the earliest. More than one card: a link to the card
 * search. None: the text stays, marked.
 *
 * Markdown is turned into HTML when a text is saved, so the links are resolved then.
 * assets/js/app.cardlinks.js does the same for the previews and for pages that render
 * markdown in the browser: change the two together.
 */
class CardLinker
{
    public const PATTERN = '/\[\[([^\[\]|\n]{1,100})(?:\|([^\[\]\n]{1,100}))?\]\]/u';

    /** fenced code, then inline code: no links inside either */
    private const CODE = '/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]*`)/';

    /** @var array<string, array{code: string, label: string, set: int, num: int, errata: ?string}>|null */
    private ?array $cards = null;
    /** @var array<int, array<string, string[]>> name keys by strength, strongest first */
    private array $tiers = [];
    private array $byColl = [];
    private array $bySetNum = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * Swaps each [[link]] outside code for a token markdown leaves alone.
     * @return array{0: string, 1: array<string, array{0: string, 1: ?string}>}
     */
    public function protect(string $markdown): array
    {
        $links = [];
        $parts = preg_split(self::CODE, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = preg_replace_callback(self::PATTERN, function (array $m) use (&$links) {
                $token = 'rbcardlink' . count($links) . 'x';
                $links[$token] = [trim($m[1]), isset($m[2]) ? trim($m[2]) : null];
                return $token;
            }, $part);
        }
        return [implode('', $parts), $links];
    }

    /** Puts the links in place of the tokens in the HTML */
    public function restore(string $html, array $links): string
    {
        if (!$links) {
            return $html;
        }
        $replace = [];
        foreach ($links as $token => [$target, $label]) {
            $replace[$token] = $this->html($target, $label);
        }
        // longest first, so rbcardlink1x never eats part of rbcardlink10x
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));
        return strtr($html, $replace);
    }

    public function html(string $target, ?string $label = null): string
    {
        $found = $this->resolve($target);
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        switch ($found['state']) {
            case 'card':
                return '<a href="' . $e($found['href']) . '" class="card-tip rb-cardlink" data-code="' . $e($found['code']) . '"'
                    . (!empty($found['image']) ? ' data-image="' . $e($found['image']) . '"' : '')
                    . '>' . $e($label ?? $found['label']) . '</a>';
            case 'ambiguous':
                return '<a href="' . $e($found['href']) . '" class="rb-cardlink is-ambiguous" title="' . $e($found['count'] . ' cards match') . '">'
                    . $e($label ?? $target) . '</a>';
            default:
                return '<span class="rb-cardlink is-missing" title="No card matches">[[' . $e($target) . ']]</span>';
        }
    }

    /**
     * @return array{state: string, href?: string, code?: string, label?: string, image?: ?string, count?: int}
     */
    public function resolve(string $target): array
    {
        $this->load();
        $target = trim($target);

        if (preg_match('/^(\d+)_(\d+)(?:\.(\d+))?$/', $target, $m)) {
            $code = $m[1] . '_' . (int) $m[2];
            $code = isset($this->cards[$code]) ? $code : $m[1] . '_' . $m[2];
            if (!isset($this->cards[$code])) {
                return ['state' => 'missing'];
            }
            return isset($m[3]) ? $this->revision($code, (int) $m[3]) : $this->found($code);
        }
        if (preg_match('/^(\d+)\s*([A-Za-z]+)\s*(\d+)$/', $target, $m)) {
            $coll = $m[1] . strtoupper($m[2]) . (int) $m[3];
            if (isset($this->byColl[$coll])) {
                return $this->found($this->byColl[$coll]);
            }
            return $this->pick($this->bySetNum[$m[1] . '_' . (int) $m[3]] ?? [], $target);
        }
        $key = self::normalize($target);
        foreach ($this->tiers as $tier) {
            if (isset($tier[$key])) {
                return $this->pick($tier[$key], $target);
            }
        }
        return ['state' => 'missing'];
    }

    /** Lower case, no accents, no punctuation, single spaces */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $s = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        $s = preg_replace("/[•·'’‘\"“”]/u", '', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim($s);
    }

    public static function initials(string $s): string
    {
        $words = explode(' ', self::normalize($s));
        return count($words) > 1 ? implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words)) : '';
    }

    /** One title (reprints of it collapse to the earliest): that card; several: the search */
    private function pick(array $codes, string $target): array
    {
        $codes = array_values(array_unique($codes));
        if (!$codes) {
            return ['state' => 'missing'];
        }
        usort($codes, fn ($a, $b) => [$this->cards[$a]['set'], $this->cards[$a]['num']] <=> [$this->cards[$b]['set'], $this->cards[$b]['num']]);
        $titles = array_unique(array_map(fn ($c) => self::normalize($this->cards[$c]['label']), $codes));
        if (count($titles) === 1) {
            return $this->found($codes[0]);
        }
        return [
            'state' => 'ambiguous',
            'count' => count($titles),
            'href' => $this->router->generate('cards_find', ['q' => $target]),
        ];
    }

    private function found(string $code): array
    {
        return [
            'state' => 'card',
            'code' => $code,
            'label' => $this->cards[$code]['label'],
            'href' => $this->router->generate('cards_zoom', ['card_code' => $code]),
        ];
    }

    /** Revision n of a card: on the PC errata blueprint's page when the PC issued it */
    private function revision(string $code, int $n): array
    {
        $base = $this->cards[$code]['errataOf'] ?? $code;
        /** @var CardVersion|null $version */
        $version = $this->em->createQuery('SELECT v FROM ' . CardVersion::class . ' v JOIN v.card c WHERE c.code = :code AND v.revision = :n')
            ->setParameters(['code' => $base, 'n' => $n])->getOneOrNullResult();
        if (!$version) {
            return ['state' => 'missing'];
        }
        $page = $base;
        if ($n > 0 && $version->getIssuer() === CardVersion::ISSUER_PC && !empty($this->cards[$base]['errata'])) {
            $page = $this->cards[$base]['errata'];
        }
        return [
            'state' => 'card',
            'code' => $page,
            'label' => $this->cards[$base]['label'] . ' (rev. ' . $n . ')',
            'image' => $version->getImageUrl(),
            'href' => $this->router->generate('cards_zoom', ['card_code' => $page]) . '#v' . $n,
        ];
    }

    private function load(): void
    {
        if ($this->cards !== null) {
            return;
        }
        $rows = $this->em->createQuery('SELECT c.code, c.kind, c.name, c.subtitle, c.collInfo, e.code AS errataOf FROM ' . Card::class . ' c LEFT JOIN c.errataOf e')
            ->getArrayResult();
        $this->cards = [];
        $this->tiers = [[], [], [], [], []];
        foreach ($rows as $row) {
            [$set, $num] = array_map('intval', explode('_', $row['code']));
            $label = $row['subtitle'] ? $row['name'] . ', ' . $row['subtitle'] : $row['name'];
            $this->cards[$row['code']] = ['code' => $row['code'], 'label' => $label, 'set' => $set, 'num' => $num, 'errata' => null, 'errataOf' => $row['errataOf']];
        }
        foreach ($rows as $row) {
            if ($row['errataOf'] && isset($this->cards[$row['errataOf']])) {
                $this->cards[$row['errataOf']]['errata'] = $row['code'];
            }
            if ($row['kind'] === Card::KIND_ERRATA) {
                continue;
            }
            $code = $row['code'];
            [$set, $num] = explode('_', $code);
            $this->bySetNum[$set . '_' . (int) $num][] = $code;
            if ($row['collInfo']) {
                $this->byColl[strtoupper(preg_replace('/\s+/', '', $row['collInfo']))] ??= $code;
            }
            $name = self::normalize($row['name']);
            $sub = $row['subtitle'] ? self::normalize($row['subtitle']) : '';
            $ini = $row['subtitle'] ? self::initials($row['subtitle']) : '';
            foreach ([
                $sub ? $name . ' ' . $sub : $name,   // the full title
                $ini ? $name . ' ' . $ini : '',      // Aragorn HTTWC
                $sub,                                // Heir to the White City
                $ini,                                // HTTWC
                $name,                               // Aragorn
            ] as $tier => $key) {
                if ($key !== '') {
                    $this->tiers[$tier][$key][] = $code;
                }
            }
        }
    }
}
