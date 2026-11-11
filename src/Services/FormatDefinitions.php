<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\Format;
use App\Repository\FormatRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Formats page's formats (Ketura, 2026-11-11): Gemp's Help > Format Definitions, from what
 * app:formats:refresh keeps of GET /hall/formats/json (each hall format's raw 'hall' row). Each
 * format's facts, its other rules and its card lists, the cards grouped by set and matched to
 * Red Book's own (an alternate printing, a promo or foil, to the card it reprints) for their
 * pages and hovers.
 */
class FormatDefinitions
{
    /** The card lists, in the order Gemp shows them */
    public const LISTS = ['banned', 'restricted', 'limit2', 'limit3', 'valid'];

    public function __construct(
        private readonly FormatRepository $formats,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The hall formats, in Gemp's order
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $formats = array_values(array_filter($this->formats->findSelectable(), fn (Format $f) => is_array($f->getRaw()['hall'] ?? null)));
        $ids = [];
        foreach ($formats as $format) {
            foreach (self::LISTS as $list) {
                foreach ($format->getRaw()['hall']['lists'][$list] ?? [] as $card) {
                    $ids[(string) ($card['id'] ?? '')] = true;
                }
            }
        }
        $cards = $this->resolveCards(array_keys(array_filter($ids, fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH)));
        return array_map(fn (Format $format) => $this->definition($format, $cards), $formats);
    }

    /** The anchor a format's section has, as Gemp's (#format-<code>) */
    public static function anchor(string $code): string
    {
        return 'format-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $code);
    }

    /** @param array<string, Card> $cards */
    private function definition(Format $format, array $cards): array
    {
        $hall = $format->getRaw()['hall'];
        $lists = [];
        foreach (self::LISTS as $key) {
            $rows = [];
            $count = 0;
            foreach ($hall['lists'][$key] ?? [] as $entry) {
                $id = (string) ($entry['id'] ?? '');
                $label = $entry['setLabel'] ?? null;
                $label = $label === null ? '?' : (string) $label;
                $rows[$label] ??= ['label' => $label, 'name' => $entry['setName'] ?? null, 'cards' => []];
                $card = $cards[$id] ?? null;
                $rows[$label]['cards'][] = [
                    'id' => $id,
                    // Gemp's unique dot as Red Book writes it
                    'name' => (string) preg_replace('/^\x{00B7}/u', "\u{2022}", (string) ($entry['name'] ?? $id)),
                    'info' => self::collectorLabel($entry),
                    'code' => $card?->getCode(),
                    'image' => $card?->getImageUrl(),
                ];
                $count++;
            }
            if ($count) {
                $lists[] = ['key' => $key, 'count' => $count, 'rows' => array_values($rows)];
            }
        }
        $errata = $hall['errata'] ?? [];
        return [
            'code' => $format->getCode(),
            'anchor' => $hall['anchor'] ?? self::anchor($format->getCode()),
            'name' => $hall['name'] ?? $format->getName(),
            'description' => $hall['description'] ?? $format->getDescription(),
            'sets' => self::setsText($hall['setSummary'] ?? $format->getSetSummary()),
            'sites' => $hall['sites'] ?? $format->getSites(),
            'cancel' => (bool) ($hall['cancelRingBearerSkirmish'] ?? false),
            'errata' => ['pc' => !empty($errata['pc']), 'pcCardsLegal' => !empty($errata['pcCardsLegal']), 'playtest' => !empty($errata['playtest'])],
            'playtest' => (bool) ($hall['playtest'] ?? $format->isPlaytest()),
            'rules' => self::otherRules($hall),
            'lists' => $lists,
            'restrictedNames' => array_values(array_map('strval', $hall['restrictedNames'] ?? [])),
            'banned' => count($hall['lists']['banned'] ?? []),
            'restricted' => count($hall['lists']['restricted'] ?? []),
        ];
    }

    /** "Cards from sets 1-10, V1-V3" as Gemp's Play popup has it reads "1-10, V1-V3" under Sets */
    private static function setsText(?string $summary): ?string
    {
        if ($summary === null || $summary === '') {
            return null;
        }
        return preg_match('/^Cards from sets? (.*)$/', $summary, $m) ? $m[1] : $summary;
    }

    /** What else the format changes, as translation keys */
    private static function otherRules(array $hall): array
    {
        $rules = [];
        foreach (['winAtEndOfRegroup' => true, 'discardPileIsPublic' => true, 'usesMaps' => true, 'winOnControlling5Sites' => true, 'ruleOfFour' => false, 'mulliganRule' => false] as $flag => $when) {
            if (array_key_exists($flag, $hall) && (bool) $hall[$flag] === $when) {
                $rules[] = 'formats.rule.' . $flag;
            }
        }
        return $rules;
    }

    /** The collector's info as printed ("1R29"); without one, the set and number ("V1 5") */
    private static function collectorLabel(array $entry): string
    {
        $info = trim((string) ($entry['collInfo'] ?? ''));
        if ($info !== '') {
            return (string) preg_replace('/_+/', ' ', $info);
        }
        if (preg_match('/^\d+_(\d+)/', (string) ($entry['id'] ?? ''), $m) && isset($entry['setLabel'])) {
            return $entry['setLabel'] . ' ' . (int) $m[1];
        }
        return '';
    }

    /**
     * Red Book's cards for Gemp's IDs: the card itself, else the card an alternate printing is of
     * @param string[] $ids
     * @return array<string, Card>
     */
    public function resolveCards(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $found = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => $ids]) as $card) {
            $found[$card->getCode()] = $card;
        }
        if (count($found) < count($ids)) {
            $missing = array_flip(array_diff($ids, array_keys($found)));
            $alternates = $this->em->createQuery("SELECT c FROM App\\Entity\\Card c WHERE c.alternates IS NOT NULL AND c.alternates <> '[]'")->getResult();
            foreach ($alternates as $card) {
                foreach ($card->getAlternates() ?? [] as $code) {
                    if (isset($missing[$code])) {
                        $found[$code] = $card;
                    }
                }
            }
        }
        return $found;
    }
}
