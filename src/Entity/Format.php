<?php

namespace App\Entity;

use App\Repository\FormatRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Gemp format, copied from Gemp by app:formats:refresh (phase3/04, decision 0020).
 * Read-only here: Red Book shows formats and offers them as deck targets, but
 * legality always comes from Gemp's deck validation (decision 0019).
 */
#[ORM\Entity(repositoryClass: FormatRepository::class)]
#[ORM\Table(name: 'gemp_format')]
#[ORM\UniqueConstraint(name: 'gemp_format_code_idx', columns: ['code'])]
class Format
{
    /** Gemp's site modes (SitesBlock, human-readable) whose adventure deck is sites 1-9, one per number */
    public const NUMBERED_SITES = ['Fellowship', 'Towers', 'King'];

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\Column(name: 'code', type: 'string', length: 50, nullable: false)]
    protected string $code;

    #[ORM\Column(name: 'name', type: 'string', length: 100, nullable: false)]
    protected string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    protected ?string $description = null;

    /** Gemp's display order */
    #[ORM\Column(name: 'position', type: 'integer', nullable: false)]
    protected int $position = 1000;

    /** Offered in Gemp's game hall; only these are deck targets */
    #[ORM\Column(name: 'hall', type: 'boolean', nullable: false)]
    protected bool $hall = false;

    #[ORM\Column(name: 'playtest', type: 'boolean', nullable: false)]
    protected bool $playtest = false;

    /** Gone from Gemp's list at the last refresh; kept because decks may still name it */
    #[ORM\Column(name: 'retired', type: 'boolean', nullable: false)]
    protected bool $retired = false;

    /** Fellowship, Towers, King, Shadows and onwards, Multipath, Hobbit, ... */
    #[ORM\Column(name: 'sites', type: 'string', length: 50, nullable: true)]
    protected ?string $sites = null;

    #[ORM\Column(name: 'minimum_deck_size', type: 'smallint', nullable: false)]
    protected int $minimumDeckSize = 60;

    #[ORM\Column(name: 'maximum_same_name', type: 'smallint', nullable: false)]
    protected int $maximumSameName = 4;

    #[ORM\Column(name: 'uses_maps', type: 'boolean', nullable: false)]
    protected bool $usesMaps = false;

    #[ORM\Column(name: 'set_summary', type: 'string', length: 255, nullable: true)]
    protected ?string $setSummary = null;

    /** Set codes the format's card pool draws from (Gemp's deckbuilder set filters) */
    #[ORM\Column(name: 'sets', type: 'json', nullable: false)]
    protected array $sets = [];

    /** PC errata apply in this format */
    #[ORM\Column(name: 'pc_errata', type: 'boolean', nullable: false)]
    protected bool $pcErrata = false;

    /** Card lists by blueprint ID: banned, restricted, limit2, limit3, valid; and restrictedNames */
    #[ORM\Column(name: 'lists', type: 'json', nullable: false)]
    protected array $lists = [];

    /** What Gemp sent, both endpoints, for anything not broken out above */
    #[ORM\Column(name: 'raw', type: 'json', nullable: false)]
    protected array $raw = [];

    #[ORM\Column(name: 'date_update', type: 'datetime', nullable: false)]
    protected DateTimeInterface $dateUpdate;

    public function __construct()
    {
        $this->dateUpdate = new DateTime();
    }

    public function getId() { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = $code; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; }
    public function isHall(): bool { return $this->hall; }
    public function setHall(bool $hall): void { $this->hall = $hall; }
    public function isPlaytest(): bool { return $this->playtest; }
    public function setPlaytest(bool $playtest): void { $this->playtest = $playtest; }
    public function isRetired(): bool { return $this->retired; }
    public function setRetired(bool $retired): void { $this->retired = $retired; }
    public function getSites(): ?string { return $this->sites; }
    public function setSites(?string $sites): void { $this->sites = $sites; }
    public function getMinimumDeckSize(): int { return $this->minimumDeckSize; }
    public function setMinimumDeckSize(int $size): void { $this->minimumDeckSize = $size; }
    public function getMaximumSameName(): int { return $this->maximumSameName; }
    public function setMaximumSameName(int $max): void { $this->maximumSameName = $max; }
    public function usesMaps(): bool { return $this->usesMaps; }
    public function setUsesMaps(bool $usesMaps): void { $this->usesMaps = $usesMaps; }
    public function getSetSummary(): ?string { return $this->setSummary; }
    public function setSetSummary(?string $summary): void { $this->setSummary = $summary; }
    public function getSets(): array { return $this->sets; }
    public function setSets(array $sets): void { $this->sets = $sets; }
    public function hasPcErrata(): bool { return $this->pcErrata; }
    public function setPcErrata(bool $pcErrata): void { $this->pcErrata = $pcErrata; }
    public function getLists(): array { return $this->lists; }
    public function setLists(array $lists): void { $this->lists = $lists; }
    public function getList(string $name): array { return $this->lists[$name] ?? []; }
    public function getRaw(): array { return $this->raw; }
    public function setRaw(array $raw): void { $this->raw = $raw; }
    public function getDateUpdate(): DateTimeInterface { return $this->dateUpdate; }
    public function setDateUpdate(DateTimeInterface $date): void { $this->dateUpdate = $date; }

    /** Sites are numbered 1-9 (one per number) rather than a free list */
    public function hasNumberedSites(): bool
    {
        return in_array($this->sites, self::NUMBERED_SITES, true);
    }

    /** Offered as a deck's target format */
    public function isSelectable(): bool
    {
        return $this->hall && !$this->retired;
    }

    /** How a blueprint ID is limited here: banned, restricted, limit2, limit3, or null */
    public function limitFor(string $code): ?string
    {
        foreach (['banned', 'restricted', 'limit2', 'limit3'] as $list) {
            if (in_array($code, $this->lists[$list] ?? [], true)) {
                return $list;
            }
        }
        return null;
    }

    public function serialize(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'hall' => $this->hall,
            'playtest' => $this->playtest,
            'retired' => $this->retired,
            'sites' => $this->sites,
            'numbered_sites' => $this->hasNumberedSites(),
            'minimum_deck_size' => $this->minimumDeckSize,
            'maximum_same_name' => $this->maximumSameName,
            'uses_maps' => $this->usesMaps,
            'set_summary' => $this->setSummary,
            'sets' => $this->sets,
            'pc_errata' => $this->pcErrata,
            'banned' => $this->getList('banned'),
            'restricted' => $this->getList('restricted'),
            'limit2' => $this->getList('limit2'),
            'limit3' => $this->getList('limit3'),
            'valid' => $this->getList('valid'),
            'restricted_names' => $this->getList('restrictedNames'),
            'date_update' => $this->dateUpdate->format('c'),
        ];
    }
}
