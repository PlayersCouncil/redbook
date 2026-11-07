<?php

namespace App\Entity;

use App\Repository\CardRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * A LOTR TCG card, keyed by its Gemp base blueprint ID (code), as imported from
 * lotrtcg-card-data (app:import:cards).
 *
 * Two kinds: "card" (a Decipher or V-set card, as Decipher left it) and "errata"
 * (a PC errata blueprint such as 51_50, errataOf its original 1_50). Decks store
 * whichever blueprint the player chose; the format decides which is legal.
 *
 * The faction column is kept for forks that use it (decision 0018) and always
 * holds the "_" placeholder in Red Book.
 */
#[ORM\Entity(repositoryClass: CardRepository::class)]
#[ORM\Table(name: 'card')]
#[ORM\Index(name: 'card_name_idx', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'card_code_idx', columns: ['code'])]
class Card implements CardInterface
{
    public const KIND_CARD = 'card';
    public const KIND_ERRATA = 'errata';

    public const SIDE_FREE_PEOPLES = 'free_peoples';
    public const SIDE_SHADOW = 'shadow';

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    /** Number within the set */
    #[ORM\Column(name: 'position', type: 'smallint', nullable: false)]
    protected $position;

    /** Gemp base blueprint ID, e.g. 1_50 */
    #[ORM\Column(name: 'code', type: 'string', length: 20, nullable: false)]
    protected $code;

    #[ORM\Column(name: 'kind', type: 'string', length: 10, nullable: false, options: ['default' => 'card'])]
    protected string $kind = self::KIND_CARD;

    /** Where the data came from: wiki, wiki+gemp (an erratum the wiki has no revision for), gemp */
    #[ORM\Column(name: 'source', type: 'string', length: 10, nullable: false, options: ['default' => 'wiki'])]
    protected string $source = 'wiki';

    /** Title */
    #[ORM\Column(name: 'name', type: 'string', length: 100, nullable: false)]
    protected $name;

    #[ORM\Column(name: 'subtitle', type: 'string', length: 100, nullable: true)]
    protected ?string $subtitle = null;

    #[ORM\Column(name: 'is_unique', type: 'boolean', nullable: false)]
    protected $isUnique = false;

    /** free_peoples, shadow, or null for sites and the One Ring */
    #[ORM\Column(name: 'side', type: 'string', length: 20, nullable: true)]
    protected ?string $side = null;

    #[ORM\Column(name: 'twilight', type: 'smallint', nullable: true)]
    protected ?int $twilight = null;

    #[ORM\Column(name: 'strength', type: 'smallint', nullable: true)]
    protected $strength;

    #[ORM\Column(name: 'strength_mod', type: 'string', length: 10, nullable: true)]
    protected ?string $strengthMod = null;

    #[ORM\Column(name: 'vitality', type: 'smallint', nullable: true)]
    protected ?int $vitality = null;

    #[ORM\Column(name: 'vitality_mod', type: 'string', length: 10, nullable: true)]
    protected ?string $vitalityMod = null;

    #[ORM\Column(name: 'resistance', type: 'smallint', nullable: true)]
    protected ?int $resistance = null;

    #[ORM\Column(name: 'resistance_mod', type: 'string', length: 10, nullable: true)]
    protected ?string $resistanceMod = null;

    /** Site number of a site, home site of an ally, site number of a minion */
    #[ORM\Column(name: 'site_number', type: 'smallint', nullable: true)]
    protected ?int $siteNumber = null;

    #[ORM\Column(name: 'site_number_mod', type: 'string', length: 10, nullable: true)]
    protected ?string $siteNumberMod = null;

    #[ORM\Column(name: 'shadow_number', type: 'smallint', nullable: true)]
    protected ?int $shadowNumber = null;

    /** For sites: the block their path belongs to (Fellowship, Towers, King, Shadows...) */
    #[ORM\Column(name: 'site_block', type: 'string', length: 30, nullable: true)]
    protected ?string $siteBlock = null;

    #[ORM\Column(name: 'signet', type: 'string', length: 30, nullable: true)]
    protected ?string $signet = null;

    #[ORM\Column(name: 'can_bear_ring', type: 'boolean', nullable: true)]
    protected ?bool $canBearRing = null;

    /** Race, item class and the like, as printed (e.g. "Elf", "Hand Weapon") */
    #[ORM\Column(name: 'subtypes', type: 'string', length: 255, nullable: true)]
    protected ?string $subtypes = null;

    /** Game text */
    #[ORM\Column(name: 'text', type: 'text', nullable: true)]
    protected $text;

    /** Lore */
    #[ORM\Column(name: 'flavor', type: 'text', nullable: true)]
    protected $flavor;

    #[ORM\Column(name: 'wiki_id', type: 'string', length: 30, nullable: true)]
    protected ?string $wikiId = null;

    #[ORM\Column(name: 'coll_info', type: 'string', length: 20, nullable: true)]
    protected ?string $collInfo = null;

    #[ORM\Column(name: 'image_url', type: 'string', length: 255, nullable: true)]
    protected $imageUrl;

    /** Gemp IDs that map to this card: promos, alternate images, reprints */
    #[ORM\Column(name: 'alternates', type: 'json', nullable: true)]
    protected ?array $alternates = null;

    /** Printings in other languages, as lotrtcg-card-data has them */
    #[ORM\Column(name: 'translations', type: 'json', nullable: true)]
    protected ?array $translations = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: false)]
    protected $dateCreation;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(name: 'date_update', type: 'datetime', nullable: false)]
    protected $dateUpdate;

    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'card')]
    #[ORM\OrderBy(['dateCreation' => 'DESC'])]
    protected $reviews;

    #[ORM\OneToMany(targetEntity: CardVersion::class, mappedBy: 'card', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['revision' => 'ASC'])]
    protected Collection $versions;

    #[ORM\ManyToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(name: 'errata_of_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Card $errataOf = null;

    #[ORM\ManyToOne(targetEntity: Culture::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'culture_id', referencedColumnName: 'id', nullable: true)]
    protected ?Culture $culture = null;

    #[ORM\ManyToOne(targetEntity: Rarity::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'rarity_id', referencedColumnName: 'id', nullable: true)]
    protected ?RarityInterface $rarity = null;

    #[ORM\ManyToOne(targetEntity: Pack::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'pack_id', referencedColumnName: 'id')]
    protected $pack;

    #[ORM\ManyToOne(targetEntity: Type::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'type_id', referencedColumnName: 'id')]
    protected $type;

    #[ORM\ManyToOne(targetEntity: Faction::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(name: 'faction_id', referencedColumnName: 'id')]
    protected $faction;

    public function __construct()
    {
        $this->reviews = new ArrayCollection();
        $this->versions = new ArrayCollection();
    }

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    public function getPosition() { return $this->position; }
    public function setPosition($position) { $this->position = $position; }
    public function getCode() { return $this->code; }
    public function setCode($code) { $this->code = $code; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): void { $this->kind = $kind; }
    public function isErrata(): bool { return $this->kind === self::KIND_ERRATA; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): void { $this->source = $source; }
    public function getName() { return $this->name; }
    public function setName($name) { $this->name = $name; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function setSubtitle(?string $subtitle): void { $this->subtitle = $subtitle; }
    public function getIsUnique() { return $this->isUnique; }
    public function setIsUnique($isUnique) { $this->isUnique = (bool) $isUnique; }
    public function getSide(): ?string { return $this->side; }
    public function setSide(?string $side): void { $this->side = $side; }
    public function getTwilight(): ?int { return $this->twilight; }
    public function setTwilight(?int $twilight): void { $this->twilight = $twilight; }
    public function getStrength() { return $this->strength; }
    public function setStrength($strength) { $this->strength = $strength; }
    public function getStrengthMod(): ?string { return $this->strengthMod; }
    public function setStrengthMod(?string $strengthMod): void { $this->strengthMod = $strengthMod; }
    public function getVitality(): ?int { return $this->vitality; }
    public function setVitality(?int $vitality): void { $this->vitality = $vitality; }
    public function getVitalityMod(): ?string { return $this->vitalityMod; }
    public function setVitalityMod(?string $vitalityMod): void { $this->vitalityMod = $vitalityMod; }
    public function getResistance(): ?int { return $this->resistance; }
    public function setResistance(?int $resistance): void { $this->resistance = $resistance; }
    public function getResistanceMod(): ?string { return $this->resistanceMod; }
    public function setResistanceMod(?string $resistanceMod): void { $this->resistanceMod = $resistanceMod; }
    public function getSiteNumber(): ?int { return $this->siteNumber; }
    public function setSiteNumber(?int $siteNumber): void { $this->siteNumber = $siteNumber; }
    public function getSiteNumberMod(): ?string { return $this->siteNumberMod; }
    public function setSiteNumberMod(?string $siteNumberMod): void { $this->siteNumberMod = $siteNumberMod; }
    public function getShadowNumber(): ?int { return $this->shadowNumber; }
    public function setShadowNumber(?int $shadowNumber): void { $this->shadowNumber = $shadowNumber; }
    public function getSiteBlock(): ?string { return $this->siteBlock; }
    public function setSiteBlock(?string $siteBlock): void { $this->siteBlock = $siteBlock; }
    public function getSignet(): ?string { return $this->signet; }
    public function setSignet(?string $signet): void { $this->signet = $signet; }
    public function getCanBearRing(): ?bool { return $this->canBearRing; }
    public function setCanBearRing(?bool $canBearRing): void { $this->canBearRing = $canBearRing; }
    public function getSubtypes(): ?string { return $this->subtypes; }
    public function setSubtypes(?string $subtypes): void { $this->subtypes = $subtypes; }
    public function getText() { return $this->text; }
    public function setText($text) { $this->text = $text; }
    public function getFlavor() { return $this->flavor; }
    public function setFlavor($flavor) { $this->flavor = $flavor; }
    public function getWikiId(): ?string { return $this->wikiId; }
    public function setWikiId(?string $wikiId): void { $this->wikiId = $wikiId; }
    public function getCollInfo(): ?string { return $this->collInfo; }
    public function setCollInfo(?string $collInfo): void { $this->collInfo = $collInfo; }
    public function getImageUrl() { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl = null) { $this->imageUrl = $imageUrl; }
    public function getAlternates(): ?array { return $this->alternates; }
    public function setAlternates(?array $alternates): void { $this->alternates = $alternates; }
    public function getTranslations(): ?array { return $this->translations; }
    public function setTranslations(?array $translations): void { $this->translations = $translations; }
    public function getDateCreation() { return $this->dateCreation; }
    public function setDateCreation($dateCreation) { $this->dateCreation = $dateCreation; }
    public function getDateUpdate() { return $this->dateUpdate; }
    public function setDateUpdate($dateUpdate) { $this->dateUpdate = $dateUpdate; }

    public function addReview(ReviewInterface $review) { $this->reviews[] = $review; return $this; }
    public function removeReview(ReviewInterface $review) { $this->reviews->removeElement($review); }
    public function getReviews() { return $this->reviews; }

    /** @return Collection<int, CardVersion> */
    public function getVersions(): Collection { return $this->versions; }
    public function addVersion(CardVersion $version): void
    {
        $version->setCard($this);
        $this->versions->add($version);
    }

    public function getErrataOf(): ?Card { return $this->errataOf; }
    public function setErrataOf(?Card $errataOf): void { $this->errataOf = $errataOf; }
    public function getCulture(): ?Culture { return $this->culture; }
    public function setCulture(?Culture $culture): void { $this->culture = $culture; }
    public function getRarity(): ?RarityInterface { return $this->rarity; }
    public function setRarity(?RarityInterface $rarity): void { $this->rarity = $rarity; }
    public function getPack() { return $this->pack; }
    public function setPack(?PackInterface $pack = null) { $this->pack = $pack; return $this; }
    /**
     * Which card template frames its picture, for a decklist's thumbnail (Ketura, 2026-11-07):
     * character (companions, allies, minions, followers), site, or modifier for the rest,
     * The One Ring included
     */
    public function getThumbnailTemplate(): string
    {
        $type = $this->getType()?->getCode();
        if (in_array($type, ['companion', 'ally', 'minion', 'follower'], true)) {
            return 'character';
        }
        return $type === 'site' ? 'site' : 'modifier';
    }

    public function getType() { return $this->type; }
    public function setType(?TypeInterface $type = null) { $this->type = $type; return $this; }
    public function getFaction() { return $this->faction; }
    public function setFaction(?FactionInterface $faction = null) { $this->faction = $faction; return $this; }

    /** "Legolas, Greenleaf" */
    public function getFullName(): string
    {
        return $this->subtitle ? $this->name . ', ' . $this->subtitle : (string) $this->name;
    }

    public function isFreePeoples(): bool { return $this->side === self::SIDE_FREE_PEOPLES; }
    public function isShadow(): bool { return $this->side === self::SIDE_SHADOW; }

    public function serialize()
    {
        return json_encode($this->__serialize());
    }

    public function unserialize($serialized)
    {
        throw new \Exception('unserialize() method unsupported');
    }

    public function __serialize(): array
    {
        return [
            'code' => $this->code, 'kind' => $this->kind, 'name' => $this->name, 'subtitle' => $this->subtitle,
            'type_code' => $this->type?->getCode(), 'culture_code' => $this->culture?->getCode(), 'side' => $this->side,
            'pack_code' => $this->pack?->getCode(), 'position' => $this->position,
        ];
    }

    public function __unserialize(array $data): void
    {
        throw new \Exception('unserialize() method unsupported');
    }

    public function __toString()
    {
        return $this->getFullName();
    }
}
