<?php

namespace App\Services\Gemp;

use App\Entity\Format;
use App\Repository\FormatRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copies Gemp's formats into Red Book's gemp_format table (phase3/04).
 *
 * Two public endpoints are merged: POST /deck/formats has every format's settings
 * and its deckbuilder set filters; GET /hall/formats/json has the card lists
 * (banned, restricted, ...) and errata flags, for hall formats only.
 */
class FormatsUpdater
{
    /** Gemp formats that exist for testing Gemp itself and are never deck targets */
    public const EXCLUDED = ['debug'];

    public function __construct(
        private readonly GempClient $gemp,
        private readonly FormatRepository $formats,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, retired: int}
     * @throws GempUnavailableException leaving the stored formats untouched
     */
    public function refresh(): array
    {
        $all = $this->gemp->formats();
        $hall = [];
        foreach ($this->gemp->hallFormats() as $row) {
            $hall[$row['code']] = $row;
        }
        if (!$all) {
            throw new GempUnavailableException('Gemp returned no formats');
        }

        $existing = $this->formats->findAllByCode();
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0];
        $now = new DateTime();
        foreach ($all as $code => $row) {
            $code = (string) ($row['code'] ?? $code);
            if (in_array($code, self::EXCLUDED, true)) {
                continue;
            }
            $format = $existing[$code] ?? null;
            $before = null;
            if ($format === null) {
                $format = new Format();
                $format->setCode($code);
                $this->em->persist($format);
            } else {
                $before = self::published($format);
            }
            $this->apply($format, $row, $hall[$code] ?? null);
            $format->setRetired(false);
            /*
             * Only a format that changed gets a new date (Ketura, 2026-11-08). Browsers keep their
             * own copy of the formats and say "a new version of the data is available" when a
             * date differs, so stamping every format on each refresh (every deploy, and hourly)
             * showed that notice for nothing.
             */
            if ($before === null) {
                $format->setDateUpdate($now);
                $counts['created']++;
            } elseif ($before !== self::published($format)) {
                $format->setDateUpdate($now);
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
            unset($existing[$code]);
        }
        foreach ($existing as $format) {
            if (!$format->isRetired()) {
                $format->setRetired(true);
                $format->setDateUpdate($now);
                $counts['retired']++;
            }
        }
        $this->em->flush();
        return $counts;
    }

    /**
     * What browsers get of a format (the formats API), without its date. Gemp's raw copy is
     * left out: browsers never see it, so a change there alone doesn't count.
     */
    private static function published(Format $format): string
    {
        $data = $format->serialize();
        unset($data['date_update']);
        return json_encode($data);
    }

    private function apply(Format $format, array $row, ?array $hall): void
    {
        $format->setName((string) $row['name']);
        $format->setDescription($row['description'] ?? $hall['description'] ?? null);
        $format->setPosition((int) ($row['order'] ?? 1000));
        $format->setHall((bool) ($row['hall'] ?? false));
        $format->setPlaytest((bool) ($row['playtest'] ?? false));
        $format->setSites($row['sites'] ?? null);
        $format->setMinimumDeckSize((int) ($row['minimumDeckSize'] ?? 60));
        $format->setMaximumSameName((int) ($row['maximumSameName'] ?? 4));
        $format->setUsesMaps((bool) ($row['usesMaps'] ?? false));
        $format->setSetSummary($row['setSummary'] ?? null);
        $format->setSets(self::setsFrom($row['blockFilters'] ?? []));
        $format->setPcErrata($hall !== null
            ? (bool) ($hall['errata']['pc'] ?? false)
            : self::hasErrataFilter($row['blockFilters'] ?? []));

        $lists = [];
        foreach (['banned', 'restricted', 'limit2', 'limit3', 'valid'] as $list) {
            $lists[$list] = array_values(array_map(fn ($card) => (string) $card['id'], $hall['lists'][$list] ?? []));
        }
        $lists['restrictedNames'] = array_values($hall['restrictedNames'] ?? []);
        $format->setLists($lists);
        $format->setRaw(['formats' => $row, 'hall' => $hall]);
    }

    /** Set codes from Gemp's deckbuilder filters, numerically ordered */
    public static function setsFrom(array $blockFilters): array
    {
        $sets = [];
        foreach ($blockFilters as $filter) {
            foreach ($filter['setFilters'] ?? [] as $set) {
                if (preg_match('/^\d+$/', (string) $set)) {
                    $sets[(int) $set] = (string) $set;
                }
            }
        }
        ksort($sets);
        return array_values($sets);
    }

    private static function hasErrataFilter(array $blockFilters): bool
    {
        foreach ($blockFilters as $filter) {
            if (($filter['filter'] ?? null) === 'pc_errata') {
                return true;
            }
        }
        return false;
    }
}
