<?php

namespace App\Command;

use App\Entity\Card;
use App\Entity\CardVersion;
use App\Entity\Culture;
use App\Entity\Cycle;
use App\Entity\ErrataBatch;
use App\Entity\Faction;
use App\Entity\Pack;
use App\Entity\Rarity;
use App\Entity\Type;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use App\Services\FormatTimeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the card data from a lotrtcg-card-data checkout (its data/ folder):
 * sets, errata batches, cards and their versions. Safe to run again: everything
 * is matched by code and updated in place. Cards that have disappeared from the
 * data are reported, not deleted, since decks may still point at them.
 */
#[AsCommand(name: 'app:import:cards', description: 'Import cards from a lotrtcg-card-data checkout')]
class ImportCardsCommand extends Command
{
    /** Gemp set number ranges -> block (Cycle): code, name. Display grouping only. */
    private const BLOCKS = [
        ['promo', 'Promotional', 0, 0],
        ['fotr', 'Fellowship', 1, 3],
        ['ttt', 'Towers', 4, 6],
        ['king', 'King', 7, 10],
        ['war', 'War of the Ring', 11, 14],
        ['hunters', 'Hunters', 15, 19],
        ['pc', "Player's Council", 100, 149],
        ['pc_errata', "Player's Council errata", 50, 69],
    ];

    private const KIND_ORDER = ['decipher' => 0, 'pc' => 1, 'pc-errata' => 2];

    /** Dataset field -> setter, for the plain fields */
    private const FIELDS = [
        'subtitle' => 'setSubtitle', 'twilight' => 'setTwilight', 'strength' => 'setStrength',
        'strengthMod' => 'setStrengthMod', 'vitality' => 'setVitality', 'vitalityMod' => 'setVitalityMod',
        'resistance' => 'setResistance', 'resistanceMod' => 'setResistanceMod', 'siteNumber' => 'setSiteNumber',
        'siteNumberMod' => 'setSiteNumberMod', 'shadowNumber' => 'setShadowNumber', 'block' => 'setSiteBlock',
        'signet' => 'setSignet', 'canBearRing' => 'setCanBearRing', 'subtypes' => 'setSubtypes',
        'gameText' => 'setText', 'lore' => 'setFlavor', 'wikiId' => 'setWikiId', 'collInfo' => 'setCollInfo',
        'image' => 'setImageUrl', 'alternates' => 'setAlternates', 'translations' => 'setTranslations',
    ];

    /** Collector's info rarity letters; anything else shows as its code */
    private const RARITY_NAMES = [
        'C' => 'Common', 'U' => 'Uncommon', 'R' => 'Rare', 'R+' => 'Rare+', 'P' => 'Promo', 'S' => 'Starter',
    ];

    private const INT_FIELDS = ['twilight', 'strength', 'vitality', 'resistance', 'siteNumber', 'shadowNumber'];

    private array $types = [];
    private array $cultures = [];
    private array $rarities = [];
    private array $batches = [];

    public function __construct(private EntityManagerInterface $em, private readonly FormatTimeline $timeline)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The lotrtcg-card-data checkout (or its data/ folder)');
    }

    public static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim($input->getArgument('path'), '/');
        if (is_dir("$dir/data")) {
            $dir .= '/data';
        }
        $version = $this->readJson("$dir/version.json");
        $io->writeln(sprintf(
            'Card data built %s from wiki revision %s',
            $version['builtAt'] ?? '?',
            $version['sources']['wiki']['revid'] ?? '?'
        ));

        $this->loadLookups();
        $faction = $this->placeholderFaction();
        $setList = $this->readJson("$dir/sets.json");
        $packs = $this->importSets($setList);
        $this->importBatches($this->readJson("$dir/errata-batches.json"));
        $this->em->flush();

        $seen = [];
        $errataLinks = [];
        $files = $this->cardFiles($dir, $setList);
        $created = $updated = 0;
        $repo = $this->em->getRepository(Card::class);
        $existing = [];
        foreach ($repo->findAll() as $card) {
            $existing[$card->getCode()] = $card;
        }
        foreach ($files as $file) {
            $set = $this->readJson($file);
            $pack = $packs[$set['set']] ?? throw new RuntimeException("set {$set['set']} isn't in sets.json");
            foreach ($set['cards'] as $record) {
                $card = $existing[$record['id']] ?? null;
                if ($card === null) {
                    $card = new Card();
                    $card->setCode($record['id']);
                    $this->em->persist($card);
                    $existing[$record['id']] = $card;
                    $created++;
                } else {
                    $updated++;
                }
                $this->fill($card, $record, $pack, $faction);
                $seen[$record['id']] = true;
                if (!empty($record['errataOf'])) {
                    $errataLinks[$record['id']] = $record['errataOf'];
                }
            }
            $this->em->flush();
            $io->writeln(sprintf('  set %s: %d cards', $set['set'], count($set['cards'])));
        }

        foreach ($existing as $code => $card) {
            $card->setErrataOf(isset($errataLinks[$code]) ? ($existing[$errataLinks[$code]] ?? null) : null);
        }
        $this->em->flush();

        $missing = array_diff(array_keys($existing), array_keys($seen));
        if ($missing) {
            $io->warning(sprintf('%d cards are no longer in the card data and were left in place: %s', count($missing), implode(', ', array_slice($missing, 0, 20))));
        }
        $io->success(sprintf('%d cards created, %d updated', $created, $updated));

        // the format timeline, when the card data carries one (Ketura, 2026-11-11)
        if (is_file("$dir/" . FormatTimeline::FILE)) {
            $done = $this->timeline->import("$dir/" . FormatTimeline::FILE);
            $io->writeln(sprintf('Format timeline %s: %d events, %d micro-formats', $done['generatedAt'] ?? '?', $done['events'], $done['microformats']));
        }
        return Command::SUCCESS;
    }

    /**
     * The per-set card files, in sets.json's order: each set names its file (Ketura,
     * 2026-11-11: 01.json, E01.json, V01.json), so nothing is guessed from the names. Data
     * from before sets.json named them: every file in cards/, by set number.
     * @return string[]
     */
    private function cardFiles(string $dir, array $sets): array
    {
        $named = array_values(array_filter(array_map(fn (array $set) => $set['file'] ?? null, $sets)));
        if (!$named) {
            $files = glob("$dir/cards/*.json") ?: [];
            usort($files, fn ($a, $b) => (int) basename($a) <=> (int) basename($b));
            return $files;
        }
        if (count($named) < count($sets)) {
            throw new RuntimeException('sets.json names a file for some sets but not all');
        }
        return array_map(fn (string $file) => "$dir/$file", $named);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("$path not found; is this a lotrtcg-card-data checkout?");
        }
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function loadLookups(): void
    {
        foreach ($this->em->getRepository(Type::class)->findAll() as $type) {
            $this->types[$type->getCode()] = $type;
        }
        foreach ($this->em->getRepository(Culture::class)->findAll() as $culture) {
            $this->cultures[$culture->getCode()] = $culture;
        }
        foreach ($this->em->getRepository(Rarity::class)->findAll() as $rarity) {
            $this->rarities[$rarity->getCode()] = $rarity;
        }
        foreach ($this->em->getRepository(ErrataBatch::class)->findAll() as $batch) {
            $this->batches[$batch->getCode()] = $batch;
        }
    }

    private function placeholderFaction(): Faction
    {
        $faction = $this->em->getRepository(Faction::class)->findOneBy(['code' => '_']);
        if (!$faction) {
            $faction = new Faction();
            $faction->setCode('_');
            $faction->setName('-');
            $faction->setIsPrimary(false);
            $this->em->persist($faction);
        }
        return $faction;
    }

    /** @return array<string, Pack> */
    private function importSets(array $sets): array
    {
        $cycles = [];
        foreach ($this->em->getRepository(Cycle::class)->findAll() as $cycle) {
            $cycles[$cycle->getCode()] = $cycle;
        }
        foreach (self::BLOCKS as $position => [$code, $name]) {
            $cycle = $cycles[$code] ?? new Cycle();
            $cycle->setCode($code);
            $cycle->setName($name);
            $cycle->setPosition($position + 1);
            $cycle->setSize(0);
            $this->em->persist($cycle);
            $cycles[$code] = $cycle;
        }

        usort($sets, fn ($a, $b) => [self::KIND_ORDER[$a['kind']] ?? 9, (int) $a['set']] <=> [self::KIND_ORDER[$b['kind']] ?? 9, (int) $b['set']]);
        $existing = [];
        foreach ($this->em->getRepository(Pack::class)->findAll() as $pack) {
            $existing[$pack->getCode()] = $pack;
        }
        $sizes = [];
        $packs = [];
        foreach ($sets as $position => $set) {
            $pack = $existing[$set['set']] ?? new Pack();
            $pack->setCode($set['set']);
            $pack->setName($set['name'] ?? ('Set ' . $set['set']));
            $pack->setPosition($position + 1);
            $pack->setSize($set['cards'] ?? 0);
            $pack->setKind($set['kind'] ?? 'decipher');
            $pack->setWikiSet($set['wikiSet'] ?? null);
            $pack->setAbbreviation($set['abbreviation'] ?? null);
            $pack->setDateRelease(!empty($set['releaseDate']) ? new DateTime($set['releaseDate']) : null);
            $cycle = $cycles[$this->blockFor((int) $set['set'])];
            $pack->setCycle($cycle);
            $sizes[$cycle->getCode()] = ($sizes[$cycle->getCode()] ?? 0) + 1;
            $this->em->persist($pack);
            $packs[$set['set']] = $pack;
        }
        foreach ($sizes as $code => $size) {
            $cycles[$code]->setSize($size);
        }
        return $packs;
    }

    private function blockFor(int $set): string
    {
        foreach (self::BLOCKS as [$code, , $low, $high]) {
            if ($set >= $low && $set <= $high) {
                return $code;
            }
        }
        throw new RuntimeException("set $set isn't in any block");
    }

    private function importBatches(array $batches): void
    {
        foreach ($batches as $row) {
            $batch = $this->batches[$row['id']] ?? new ErrataBatch();
            $batch->setCode($row['id']);
            $batch->setIssuer($row['issuer']);
            $batch->setReleaseDate(new DateTime($row['releaseDate']));
            $batch->setName($row['name'] ?? null);
            $this->em->persist($batch);
            $this->batches[$row['id']] = $batch;
        }
    }

    private function fill(Card $card, array $record, Pack $pack, Faction $faction): void
    {
        $card->setPosition($record['number']);
        $card->setKind($record['kind']);
        $card->setSource($record['source'] ?? 'wiki');
        $card->setName($record['title'] ?? $record['id']);
        $card->setIsUnique((bool) ($record['unique'] ?? false));
        $card->setSide(match ($record['side'] ?? null) {
            'Free Peoples' => Card::SIDE_FREE_PEOPLES,
            'Shadow' => Card::SIDE_SHADOW,
            default => null,
        });
        foreach (self::FIELDS as $field => $setter) {
            $value = $record[$field] ?? null;
            if (in_array($field, self::INT_FIELDS, true)) {
                $value = is_numeric($value) ? (int) $value : null;
            }
            // The wiki writes "None" for an empty signet or site block
            if ($value === '' || ($value === 'None' && in_array($field, ['signet', 'block'], true))) {
                $value = null;
            }
            $card->$setter($value);
        }
        $card->setPack($pack);
        $card->setFaction($faction);
        $card->setType($this->type($record['type'] ?? 'Unknown'));
        $card->setCulture(!empty($record['culture']) ? $this->culture($record['culture'], $card->getSide()) : null);
        $card->setRarity(!empty($record['rarity']) ? $this->rarity($record['rarity']) : null);

        // Versions are matched by their wiki ID and updated in place (inserting before the
        // old rows are gone would trip the unique wiki_id)
        $byWikiId = [];
        foreach ($card->getVersions() as $version) {
            $byWikiId[$version->getWikiId()] = $version;
        }
        $kept = [];
        foreach ($record['versions'] ?? [] as $row) {
            $version = $byWikiId[$row['wikiId']] ?? null;
            if ($version === null) {
                $version = new CardVersion();
                $version->setWikiId($row['wikiId']);
                $card->addVersion($version);
            }
            $version->setRevision($row['revision']);
            $version->setIssuer($row['issuer'] ?? null);
            $version->setReleaseDate($this->date($row['releaseDate'] ?? null));
            $version->setBatch(!empty($row['batch']) ? ($this->batches[$row['batch']] ?? null) : null);
            $version->setFields($row['fields'] ?? []);
            $version->setImageUrl($row['image'] ?? null);
            $kept[$row['wikiId']] = true;
        }
        foreach ($byWikiId as $wikiId => $version) {
            if (!isset($kept[$wikiId])) {
                $card->getVersions()->removeElement($version);
            }
        }
    }

    private function date(?string $value): ?DateTime
    {
        if (!$value) {
            return null;
        }
        // The wiki allows year-month and year-only dates
        if (preg_match('/^\d{4}$/', $value)) {
            $value .= '-01-01';
        } elseif (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }
        return new DateTime($value);
    }

    private function type(string $name): Type
    {
        $code = self::slug($name);
        if (!isset($this->types[$code])) {
            $type = new Type();
            $type->setCode($code);
            $type->setName($name);
            $this->em->persist($type);
            $this->types[$code] = $type;
        }
        return $this->types[$code];
    }

    private function culture(string $name, ?string $side): Culture
    {
        $code = self::slug($name);
        if (!isset($this->cultures[$code])) {
            $culture = new Culture();
            $culture->setCode($code);
            $culture->setName($name);
            $this->em->persist($culture);
            $this->cultures[$code] = $culture;
        }
        $culture = $this->cultures[$code];
        if ($culture->getSide() === null && $side !== null) {
            $culture->setSide($side);
        }
        return $culture;
    }

    private function rarity(string $code): Rarity
    {
        if (!isset($this->rarities[$code])) {
            $rarity = new Rarity();
            $rarity->setCode($code);
            $this->em->persist($rarity);
            $this->rarities[$code] = $rarity;
        }
        $this->rarities[$code]->setName(self::RARITY_NAMES[$code] ?? $code);
        return $this->rarities[$code];
    }
}
