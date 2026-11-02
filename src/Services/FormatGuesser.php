<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\CardVersion;
use App\Entity\ErrataBatch;
use App\Entity\Format;
use App\Repository\FormatRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Guesses which format a deck was built for (Ketura, 2026-11-02), for seed and
 * text imports that don't say, and the deckbuilder's "Suggest a format":
 *
 * 1. Date: formats introduced after the deck's date are out (config/format_guess.yaml).
 * 2. Site path: the block most of the sites come from (a minority from other
 *    blocks is ignored) keeps only formats played on that path.
 * 3. Sets: every other card's set (Ring-bearer and Ring included) must be legal
 *    in the format; the formats with the fewest cards outside their sets stay.
 *    A map counts against formats that don't use maps.
 * 4. The rest are ranked by popularity (config), then by how few sets the format
 *    allows ("tightness").
 *
 * Only Gemp's hall formats are considered. This is a guess with its reasons
 * shown, never a verdict: Gemp's validation still decides legality.
 */
class FormatGuesser
{
    /** Gemp's site modes that each site block's path is played in */
    private const PATHS = [
        'Fellowship' => ['Fellowship', 'Multipath'],
        'Towers' => ['Towers', 'Multipath'],
        'King' => ['King', 'Multipath'],
        'Shadows' => ['Shadows and onwards'],
    ];

    /**
     * The block a site belongs to when the card data doesn't say (some starter
     * sites and the V-set sites have no Block on the wiki): from its set
     */
    private const SITE_BLOCK_BY_SET = [
        '1' => 'Fellowship', '2' => 'Fellowship', '3' => 'Fellowship', '101' => 'Fellowship',
        '4' => 'Towers', '5' => 'Towers', '6' => 'Towers', '102' => 'Towers',
        '7' => 'King', '8' => 'King', '9' => 'King', '10' => 'King', '103' => 'King',
    ];

    private ?array $config = null;
    private ?DateTimeInterface $pcEraStart = null;

    public function __construct(
        private readonly FormatRepository $formats,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/config/format_guess.yaml')] private readonly string $configFile,
    ) {
    }

    /**
     * @param iterable<array{0: Card, 1: string, 2: int}> $slots [card, role, quantity]
     * @param DateTimeInterface|null $date when the deck was built or played; null for "now"
     * @return array{path: ?string, sets: array<string, int>, candidates: list<array{format: Format, popularity: int, offFormat: int, reasons: string[]}>}
     */
    public function guess(iterable $slots, ?DateTimeInterface $date = null): array
    {
        $config = $this->config();
        $sites = [];
        $sets = [];
        $hasMap = false;
        foreach ($slots as [$card, $role, $quantity]) {
            if ($role === DeckContent::ROLE_SITE) {
                $set = (string) $card->getPack()->getCode();
                $block = $card->getSiteBlock() ?: (self::SITE_BLOCK_BY_SET[$set] ?? ((int) $set >= 11 && (int) $set < 50 ? 'Shadows' : null));
                if ($block) {
                    $sites[$block] = ($sites[$block] ?? 0) + $quantity;
                }
                continue;
            }
            if ($role === DeckContent::ROLE_MAP) {
                $hasMap = true;
                continue;
            }
            $set = (string) $card->getPack()->getCode();
            $sets[$set] = ($sets[$set] ?? 0) + $quantity;
        }
        arsort($sites);
        $path = array_key_first($sites);
        ksort($sets, SORT_NATURAL);

        $candidates = [];
        foreach ($this->formats->findSelectable() as $format) {
            if ($format->isPlaytest() || in_array($format->getCode(), $config['excluded'] ?? [], true)) {
                continue;
            }
            $reasons = [];
            $introduced = $this->introduced($format);
            if ($date !== null && $introduced !== null && $date < $introduced) {
                continue;
            }
            if ($path !== null && !in_array($format->getSites(), self::PATHS[$path] ?? [], true)) {
                continue;
            }
            if ($path !== null) {
                $reasons[] = sprintf('%s sites', $path);
            }
            $legal = array_flip($format->getSets());
            $off = 0;
            foreach ($sets as $set => $count) {
                if (!isset($legal[$set])) {
                    $off += $count;
                }
            }
            if ($hasMap && !$format->usesMaps()) {
                $off++;
            } elseif ($hasMap) {
                $reasons[] = 'uses a map';
            }
            $reasons[] = $off === 0 ? 'every card is in its sets' : sprintf('%d %s outside its sets', $off, $off === 1 ? 'card' : 'cards');
            $candidates[] = [
                'format' => $format,
                'popularity' => (int) ($config['popularity'][$format->getCode()] ?? 1),
                'offFormat' => $off,
                'reasons' => $reasons,
            ];
        }

        // keep the formats that fit the cards best, then rank them
        $best = $candidates ? min(array_column($candidates, 'offFormat')) : 0;
        $candidates = array_values(array_filter($candidates, fn ($c) => $c['offFormat'] === $best));
        usort($candidates, fn ($a, $b) => [$b['popularity'], count($a['format']->getSets()), $a['format']->getPosition()]
            <=> [$a['popularity'], count($b['format']->getSets()), $b['format']->getPosition()]);

        return ['path' => $path, 'sets' => $sets, 'candidates' => $candidates];
    }

    /** The guess for a content map (DeckContent keys); null when nothing fits */
    public function guessContent(array $content, ?DateTimeInterface $date = null): ?Format
    {
        return $this->guess($this->slotsFromContent($content), $date)['candidates'][0]['format'] ?? null;
    }

    /** @return list<array{0: Card, 1: string, 2: int}> */
    public function slotsFromContent(array $content): array
    {
        $codes = array_map(fn ($key) => DeckContent::parseKey((string) $key)[0], array_keys($content));
        $cards = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => array_unique($codes)]) as $card) {
            $cards[$card->getCode()] = $card;
        }
        $slots = [];
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer] = DeckContent::parseKey((string) $key);
            if (isset($cards[$code])) {
                $role = $isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($cards[$code]->getType()->getCode());
                $slots[] = [$cards[$code], $role, (int) $quantity];
            }
        }
        return $slots;
    }

    private function introduced(Format $format): ?DateTimeInterface
    {
        $date = $this->config()['introduced'][$format->getCode()] ?? null;
        if ($date !== null) {
            return is_int($date) ? (new DateTime())->setTimestamp($date) : new DateTime((string) $date);
        }
        $pc = $format->hasPcErrata() || array_filter($format->getSets(), fn ($set) => (int) $set >= 100);
        return $pc ? $this->pcEraStart() : null;
    }

    /** The first Players Council errata batch: no PC format existed before it */
    private function pcEraStart(): DateTimeInterface
    {
        if ($this->pcEraStart === null) {
            $first = $this->em->getRepository(ErrataBatch::class)->findOneBy(['issuer' => CardVersion::ISSUER_PC], ['releaseDate' => 'ASC']);
            $this->pcEraStart = $first?->getReleaseDate() ?? new DateTime('2020-01-01');
        }
        return $this->pcEraStart;
    }

    private function config(): array
    {
        return $this->config ??= Yaml::parseFile($this->configFile);
    }
}
