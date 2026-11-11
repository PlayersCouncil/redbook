<?php

namespace App\Entity;

use App\Repository\PublishedDeckRepository;
use App\Classes\SlotCollectionDecorator;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Gedmo\Mapping\Annotation as Gedmo;
use JsonSerializable;
use Doctrine\ORM\Mapping as ORM;
use stdClass;

#[ORM\Entity(repositoryClass: PublishedDeckRepository::class)]
#[ORM\Table(name: 'published_deck')]
#[ORM\Index(name: 'published_deck_format_idx', columns: ['target_format', 'completeness', 'half'])]
#[ORM\Index(name: 'published_deck_review_idx', columns: ['review_status', 'reviewed_at'])]
#[ORM\UniqueConstraint(name: 'published_deck_line_version', columns: ['line_id', 'version'])]
class PublishedDeck extends CommonDeck implements JsonSerializable, PublishedDeckInterface, \App\Classes\ThumbnailSubject, Reactable
{
    /**
     * How long after publishing the author can still withdraw a decklist to fix it
     * (Ketura, 2026-11-07). After that its cards, half, format and date are fixed, and
     * changing them means publishing a new version.
     */
    public const GRACE_HOURS = 72;

    /** The Cheers with each fire and crown weighed in (Reactable::POINTS), for DQL on PublishedDeck d */
    public const SCORE_DQL = '(d.nbVotes + ' . self::POINTS[self::FIRE] . ' * d.nbFires + ' . self::POINTS[self::CROWN] . ' * d.nbCrowns)';

    use LotrDeckTrait;

    /** Free Peoples or Shadow only (decision 0023); null for a whole deck */
    #[ORM\Column(name: 'half', type: 'string', length: 12, nullable: true)]
    protected ?string $half = null;

    /** @var Collection<int, PublishedDeckCulture> */
    #[ORM\OneToMany(targetEntity: PublishedDeckCulture::class, mappedBy: 'decklist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $cultures;

    /** @var Collection<int, PublishedDeckPlay> */
    #[ORM\OneToMany(targetEntity: PublishedDeckPlay::class, mappedBy: 'decklist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['eventDate' => 'DESC'])]
    protected $plays;

    public const REVIEW_UNVERIFIED = 'unverified';
    public const REVIEW_VERIFIED = 'verified';

    /**
     * Archive decks wait for a historian to check them against the source
     * (Ketura, 2026-11-02); null for decks people publish themselves.
     */
    #[ORM\Column(name: 'review_status', type: 'string', length: 12, nullable: true)]
    protected ?string $reviewStatus = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $reviewedBy = null;

    #[ORM\Column(name: 'reviewed_at', type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $reviewedAt = null;

    /**
     * What the import couldn't settle, for the review queue: a list of codes
     * (unresolved, format_guessed, problems, no_format, parse) and their details.
     *
     * @var array<array{code: string, detail?: string}>|null
     */
    #[ORM\Column(name: 'review_flags', type: 'json', nullable: true)]
    protected ?array $reviewFlags = null;

    /** Set for decklists seeded from the archive (phase3/06) */
    #[ORM\OneToOne(targetEntity: PublishedDeckSource::class, mappedBy: 'decklist', cascade: ['persist', 'remove'])]
    protected ?PublishedDeckSource $source = null;

    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    protected $name;

    /**
     * @var DateTime
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: false)]
    protected $dateCreation;

    /**
     * @var DateTime
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(name: 'date_update', type: 'datetime', nullable: false)]
    protected $dateUpdate;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'description_md', type: 'text', nullable: true)]
    protected $descriptionMd;

    /**
     * @var string
     */
    #[ORM\Column(name: 'name_canonical', type: 'string', length: 255)]
    protected $nameCanonical;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'description_html', type: 'text', nullable: true)]
    protected $descriptionHtml;

    /**
     * @var string
     */
    #[ORM\Column(name: 'signature', type: 'string', length: 32)]
    protected $signature;

    /**
     * @var int
     */
    #[ORM\Column(name: 'nb_votes', type: 'integer')]
    protected $nbVotes;

    /**
     * Fires (Ketura, 2026-11-04) and crowns (2026-11-11): the steps up from a Cheers, each
     * counted in the vote table's level (App\Services\Reactions)
     */
    #[ORM\Column(name: 'nb_fires', type: 'integer', options: ['default' => 0])]
    protected int $nbFires = 0;

    #[ORM\Column(name: 'nb_crowns', type: 'integer', options: ['default' => 0])]
    protected int $nbCrowns = 0;

    /**
     * @var int
     */
    #[ORM\Column(name: 'nb_favorites', type: 'integer')]
    protected $nbFavorites;

    /**
     * The draw deck's size on each side (Ketura, 2026-11-05): the list shows them as 30/30, and a
     * side with no cards marks a deck fragment, whether published as a half or archived as one.
     * Kept by updateSizes() so lists can show and filter on them without the slots.
     */
    #[ORM\Column(name: 'nb_free_peoples', type: 'smallint', options: ['default' => 0])]
    protected int $nbFreePeoples = 0;

    #[ORM\Column(name: 'nb_shadow', type: 'smallint', options: ['default' => 0])]
    protected int $nbShadow = 0;

    /**
     * The card the deck's thumbnail shows (Ketura, 2026-11-05), chosen when publishing; without
     * one the thumbnail shows the deck's two main cultures (getThumbnailCultures).
     */
    #[ORM\ManyToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(name: 'thumbnail_card_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Card $thumbnailCard = null;

    /**
     * The cultures a thumbnail without a card shows, [{code, name, corner}], worked out from the
     * draw deck by updateSizes() (see pickThumbnailCultures)
     */
    #[ORM\Column(name: 'thumbnail_cultures', type: 'json', nullable: true)]
    protected ?array $thumbnailCultures = null;

    /**
     * A framing of its own for a thumbnail card (Ketura, 2026-11-07), set with the crop editor:
     * {code, w, l, t}, the picture's width and left offset as percentages of the tile's width
     * and its top offset as a percentage of the tile's height. One is kept, for the last card
     * adjusted, and it applies whenever that card is the thumbnail; the others use their
     * template's framing (style.css, .rb-thumb-tpl-*).
     */
    #[ORM\Column(name: 'thumbnail_crop', type: 'json', nullable: true)]
    protected ?array $thumbnailCrop = null;

    /**
     * @var int
     */
    #[ORM\Column(name: 'nb_comments', type: 'integer')]
    protected $nbComments;

    /** Its number within its line of versions: 1, 2, 3 (Ketura, 2026-11-07) */
    #[ORM\Column(name: 'version', type: 'integer')]
    protected $version;

    /**
     * Edits to its public details since it was published (Ketura, 2026-11-08): 2.0.1, 2.0.2.
     * Shown in its Details and History; elsewhere it's 2.0.
     */
    #[ORM\Column(name: 'patch', type: 'integer', options: ['default' => 0])]
    protected int $patch = 0;

    #[ORM\ManyToOne(targetEntity: DecklistLine::class, inversedBy: 'decklists', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'line_id', referencedColumnName: 'id', nullable: false)]
    protected ?DecklistLine $line = null;

    /**
     * Withdrawn by its author while the grace period lasts (Ketura, 2026-11-07): hidden
     * from everyone else, its number kept, until it is published again.
     */
    #[ORM\Column(name: 'withdrawn', type: 'boolean', options: ['default' => false])]
    protected bool $withdrawn = false;

    /**
     * Its tags (Ketura, 2026-11-07): taken from the deck when it's published, then the
     * decklist's own, editable with the rest of its notes. Space-separated, as the deck's.
     */
    #[ORM\Column(name: 'tags', type: 'string', length: 4000, nullable: true)]
    protected ?string $tags = null;

    /** When it last went public; the grace period counts from here, and starts again on re-publishing */
    #[ORM\Column(name: 'date_published', type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $datePublished = null;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: PublishedDeckSlot::class, mappedBy: 'decklist', cascade: ['persist', 'remove'])]
    protected $slots;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'decklist', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['dateCreation' => 'ASC'])]
    protected $comments;

    /**
     * @var Collection
     */
    #[ORM\ManyToMany(targetEntity: PublishedDeck::class, mappedBy: 'precedents')]
    #[ORM\OrderBy(['dateCreation' => 'ASC'])]
    protected $successors;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: Deck::class, mappedBy: 'parent')]
    protected $children;

    /**
     * @var UserInterface
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'decklists')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    protected $user;

    /**
     * @var FactionInterface
     */
    #[ORM\ManyToOne(targetEntity: Faction::class)]
    #[ORM\JoinColumn(name: 'faction_id', referencedColumnName: 'id')]
    protected $faction;

    /**
     * @var PackInterface
     */
    #[ORM\ManyToOne(targetEntity: Pack::class)]
    #[ORM\JoinColumn(name: 'last_pack_id', referencedColumnName: 'id')]
    protected $lastPack;

    /**
     * @var DeckInterface
     */
    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_deck_id', referencedColumnName: 'id')]
    protected $parent;

    /**
     * @var PublishedDeckInterface
     */
    /**
     * The decks it was derived from (Ketura, 2026-11-08: several, where the author knows of a
     * handful that shaped it; it was one)
     */
    #[ORM\ManyToMany(targetEntity: PublishedDeck::class, inversedBy: 'successors')]
    #[ORM\JoinTable(name: 'published_deck_precedent')]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'precedent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['dateCreation' => 'ASC'])]
    protected Collection $precedents;

    /**
     * @var TournamentInterface
     */
    #[ORM\ManyToOne(targetEntity: Tournament::class, inversedBy: 'decklists')]
    #[ORM\JoinColumn(name: 'tournament_id', referencedColumnName: 'id')]
    protected $tournament;

    /**
     * @var Collection
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'favorites')]
    #[ORM\JoinTable(name: 'favorite', joinColumns: [new ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')])]
    protected $favorites;

    /**
     * @var Collection
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'votes')]
    #[ORM\JoinTable(name: 'vote', joinColumns: [new ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')])]
    protected $votes;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->slots = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->successors = new ArrayCollection();
        $this->precedents = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->favorites = new ArrayCollection();
        $this->votes = new ArrayCollection();
        $this->cultures = new ArrayCollection();
        $this->plays = new ArrayCollection();
    }

    public function getHalf(): ?string
    {
        return $this->half;
    }

    public function setHalf(?string $half): void
    {
        $this->half = $half;
    }

    /** @return Collection<int, PublishedDeckCulture> */
    public function getCultures(): Collection
    {
        return $this->cultures;
    }

    /** Rebuilds the culture counts from the draw deck (phase3/03) */
    public function updateCultures(): void
    {
        // updated in place: the rows are keyed by (decklist, culture), and Doctrine
        // inserts before it deletes
        $counts = $this->getSlots()->getCultures();
        foreach ($this->cultures->toArray() as $row) {
            $code = $row->getCulture()->getCode();
            if (isset($counts[$code])) {
                $row->setCount($counts[$code]['count']);
                unset($counts[$code]);
            } else {
                $this->cultures->removeElement($row);
            }
        }
        foreach ($counts as ['culture' => $culture, 'count' => $count]) {
            $this->cultures->add(new PublishedDeckCulture($this, $culture, $count));
        }
    }

    public function getThumbnailCard(): ?Card
    {
        return $this->thumbnailCard;
    }

    public function setThumbnailCard(?Card $card): void
    {
        $this->thumbnailCard = $card;
    }

    public function getThumbnailCrop(): ?array
    {
        return $this->thumbnailCrop;
    }

    public function setThumbnailCrop(?array $crop): void
    {
        $this->thumbnailCrop = $crop;
    }

    /** The custom framing for this card, if it's the one that has it */
    public function getThumbnailCropFor(?Card $card): ?array
    {
        return $card && $this->thumbnailCrop && ($this->thumbnailCrop['code'] ?? null) === $card->getCode() ? $this->thumbnailCrop : null;
    }

    /** @return array<array{code: string, name: string, corner: string, side: string}> see pickThumbnailCultures */
    public function getThumbnailCultures(): array
    {
        return $this->thumbnailCultures ?? [];
    }

    /**
     * The cultures a thumbnail without a card shows (Ketura, 2026-11-05), from the draw deck's
     * card counts by side and culture. A whole deck: the Free Peoples culture with the most Free
     * Peoples cards top left, the Shadow culture with the most Shadow cards bottom right; Gollum,
     * whose cards are on both sides, can be either or both. A fragment, or a deck with cards on
     * one side only: that side's main culture filling the tile. Ties go to the first by name.
     *
     * @param array<string, array<string, array{name: string, count: int}>> $counts side => culture code => name and count
     * @return array<array{code: string, name: string, corner: string, side: string}> corner: fp, shadow or full
     */
    public static function pickThumbnailCultures(array $counts): array
    {
        $top = function (string $side) use ($counts): ?array {
            $rows = [];
            foreach ($counts[$side] ?? [] as $code => $row) {
                if ($row['count'] > 0) {
                    $rows[] = ['code' => $code, 'name' => $row['name'], 'count' => $row['count']];
                }
            }
            usort($rows, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
            return $rows[0] ?? null;
        };
        $fp = $top(Card::SIDE_FREE_PEOPLES);
        $shadow = $top(Card::SIDE_SHADOW);
        // side: the side whose cards the culture was counted on, for Gollum's colours and framing
        $part = fn (array $row, string $corner, string $side) => ['code' => $row['code'], 'name' => $row['name'], 'corner' => $corner, 'side' => $side];
        if ($fp && $shadow) {
            return [$part($fp, 'fp', Card::SIDE_FREE_PEOPLES), $part($shadow, 'shadow', Card::SIDE_SHADOW)];
        }
        if ($fp) {
            return [$part($fp, 'full', Card::SIDE_FREE_PEOPLES)];
        }
        return $shadow ? [$part($shadow, 'full', Card::SIDE_SHADOW)] : [];
    }

    /** Recounts the draw deck's Free Peoples and Shadow cards (see $nbFreePeoples), and picks the thumbnail's cultures */
    public function updateSizes(): void
    {
        $count = fn ($slots) => array_sum(array_map(fn ($slot) => (int) $slot->getQuantity(), iterator_to_array($slots)));
        $this->nbFreePeoples = $count($this->getSlots()->getFreePeoples());
        $this->nbShadow = $count($this->getSlots()->getShadow());

        // and the thumbnail's cultures, which come from the same counts by side
        $counts = [];
        foreach ($this->getSlots()->getDrawDeck() as $slot) {
            $card = $slot->getCard();
            if ($card->getSide() && $card->getCulture()) {
                $row = &$counts[$card->getSide()][$card->getCulture()->getCode()];
                $row ??= ['name' => $card->getCulture()->getName(), 'count' => 0];
                $row['count'] += (int) $slot->getQuantity();
                unset($row);
            }
        }
        $this->thumbnailCultures = self::pickThumbnailCultures($counts);
    }

    public function getNbFreePeoples(): int
    {
        return $this->nbFreePeoples;
    }

    public function getNbShadow(): int
    {
        return $this->nbShadow;
    }

    /** whole, free_peoples or shadow: the side a fragment has; whole when it has both (or neither) */
    public function getKind(): string
    {
        if ($this->nbShadow === 0 && $this->nbFreePeoples > 0) {
            return Card::SIDE_FREE_PEOPLES;
        }
        if ($this->nbFreePeoples === 0 && $this->nbShadow > 0) {
            return Card::SIDE_SHADOW;
        }
        return 'whole';
    }

    /** @return Collection<int, PublishedDeckPlay> */
    public function getPlays(): Collection
    {
        return $this->plays;
    }

    public function addPlay(PublishedDeckPlay $play): void
    {
        $play->setDecklist($this);
        $this->plays->add($play);
    }

    public function removePlay(PublishedDeckPlay $play): void
    {
        $this->plays->removeElement($play);
    }

    public function getReviewStatus(): ?string
    {
        return $this->reviewStatus;
    }

    public function isUnverified(): bool
    {
        return $this->reviewStatus === self::REVIEW_UNVERIFIED;
    }

    public function isVerified(): bool
    {
        return $this->reviewStatus === self::REVIEW_VERIFIED;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function markUnverified(): void
    {
        $this->reviewStatus = self::REVIEW_UNVERIFIED;
        $this->reviewedBy = null;
        $this->reviewedAt = null;
    }

    public function markVerified(User $by): void
    {
        $this->reviewStatus = self::REVIEW_VERIFIED;
        $this->reviewedBy = $by;
        $this->reviewedAt = new DateTime();
    }

    /** @return array<array{code: string, detail?: string}> */
    public function getReviewFlags(): array
    {
        return $this->reviewFlags ?? [];
    }

    public function setReviewFlags(?array $flags): void
    {
        $this->reviewFlags = $flags ?: null;
    }

    public function getSource(): ?PublishedDeckSource
    {
        return $this->source;
    }

    public function setSource(?PublishedDeckSource $source): void
    {
        $this->source = $source;
    }

    /**
     * @inheritdoc
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function setNameCanonical($nameCanonical)
    {
        $this->nameCanonical = $nameCanonical;
    }

    /**
     * @inheritdoc
     */
    public function getNameCanonical()
    {
        return $this->nameCanonical;
    }

    /**
     * @inheritdoc
     */
    public function setDateCreation($dateCreation)
    {
        $this->dateCreation = $dateCreation;
    }

    /**
     * @inheritdoc
     */
    public function getDateCreation()
    {
        return $this->dateCreation;
    }

    /**
     * @inheritdoc
     */
    public function setDateUpdate($dateUpdate)
    {
        $this->dateUpdate = $dateUpdate;
    }

    /**
     * @inheritdoc
     */
    public function getDateUpdate()
    {
        return $this->dateUpdate;
    }

    /**
     * @inheritdoc
     */
    public function setDescriptionMd($descriptionMd)
    {
        $this->descriptionMd = $descriptionMd;
    }

    /**
     * @inheritdoc
     */
    public function getDescriptionMd()
    {
        return $this->descriptionMd;
    }

    /**
     * @inheritdoc
     */
    public function setDescriptionHtml($descriptionHtml)
    {
        $this->descriptionHtml = $descriptionHtml;
    }

    /**
     * @inheritdoc
     */
    public function getDescriptionHtml()
    {
        return $this->descriptionHtml;
    }

    /**
     * @inheritdoc
     */
    public function setSignature($signature)
    {
        $this->signature = $signature;
    }

    /**
     * @inheritdoc
     */
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * @inheritdoc
     */
    public function setNbVotes($nbVotes)
    {
        $this->nbVotes = $nbVotes;
    }

    /**
     * @inheritdoc
     */
    public function getnbVotes()
    {
        return $this->nbVotes;
    }

    public function getNbFires(): int
    {
        return $this->nbFires;
    }

    public function setNbFires(int $nbFires): void
    {
        $this->nbFires = max(0, $nbFires);
    }

    public function getNbCrowns(): int
    {
        return $this->nbCrowns;
    }

    /** The Cheers with each fire and crown weighed in, as SCORE_DQL */
    public function getScore(): int
    {
        return (int) $this->nbVotes + self::POINTS[self::FIRE] * $this->nbFires + self::POINTS[self::CROWN] * $this->nbCrowns;
    }

    public function getReactionCounts(): array
    {
        return [(int) $this->nbVotes, $this->nbFires, $this->nbCrowns];
    }

    public function setReactionCounts(int $cheers, int $fires, int $crowns): void
    {
        $this->nbVotes = max(0, $cheers);
        $this->nbFires = max(0, $fires);
        $this->nbCrowns = max(0, $crowns);
    }

    /**
     * @inheritdoc
     */
    public function setNbFavorites($nbFavorites)
    {
        $this->nbFavorites = $nbFavorites;
    }

    /**
     * @inheritdoc
     */
    public function getNbFavorites()
    {
        return $this->nbFavorites;
    }

    /**
     * @inheritdoc
     */
    public function setNbComments($nbComments)
    {
        $this->nbComments = $nbComments;
    }

    /**
     * @inheritdoc
     */
    public function getNbComments()
    {
        return $this->nbComments;
    }

    /**
     * @inheritdoc
     */
    public function getSlots()
    {
        return new SlotCollectionDecorator($this->slots);
    }

    /**
     * @inheritdoc
     */
    public function addSlot(PublishedDeckSlotInterface $slot)
    {
        $this->slots->add($slot);
    }

    /**
     * @inheritdoc
     */
    public function removeSlot(PublishedDeckSlotInterface $slot)
    {
        $this->slots->removeElement($slot);
    }

    /**
     * @inheritdoc
     */
    public function addComment(CommentInterface $comment)
    {
        $this->comments->add($comment);
    }

    /**
     * @inheritdoc
     */
    public function removeComment(CommentInterface $comment)
    {
        $this->comments->removeElement($comment);
    }

    /**
     * @inheritdoc
     */
    public function getComments()
    {
        return $this->comments;
    }

    /**
     * @inheritdoc
     */
    public function addSuccessor(PublishedDeckInterface $successor)
    {
        $this->successors->add($successor);
    }

    /**
     * @inheritdoc
     */
    public function removeSuccessor(PublishedDeckInterface $successor)
    {
        $this->successors->removeElement($successor);
    }

    /**
     * @inheritdoc
     */
    public function getSuccessors()
    {
        return $this->successors;
    }

    /**
     * @inheritdoc
     */
    public function addChild(DeckInterface $child)
    {
        $this->children->add($child);
    }

    /**
     * @inheritdoc
     */
    public function removeChild(DeckInterface $child)
    {
        $this->children->removeElement($child);
    }

    /**
     * @inheritdoc
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @inheritdoc
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @inheritdoc
     */
    public function setUser(?UserInterface $user = null)
    {
        $this->user = $user;
    }

    /**
     * @inheritdoc
     */
    public function getFaction()
    {
        return $this->faction;
    }

    /**
     * @inheritdoc
     */
    public function setFaction(?FactionInterface $faction = null)
    {
        $this->faction = $faction;
    }

    /**
     * @inheritdoc
     */
    public function setLastPack(?PackInterface $lastPack = null)
    {
        $this->lastPack = $lastPack;
    }

    /**
     * @inheritdoc
     */
    public function getLastPack()
    {
        return $this->lastPack;
    }

    /**
     * @inheritdoc
     */
    public function setParent(?DeckInterface $parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * The deck it was published from; none once its owner deleted it (Ketura, 2026-11-08)
     */
    public function getParent()
    {
        return $this->parent instanceof Deck && $this->parent->isDeleted() ? null : $this->parent;
    }

    /** The deck it was published from, even deleted, which Recreate brings back */
    public function getStoredParent(): ?Deck
    {
        return $this->parent;
    }

    /**
     * @inheritdoc
     */
    public function setPrecedent(?PublishedDeckInterface $precedent = null)
    {
        $this->setPrecedents($precedent ? [$precedent] : []);
    }

    /** The first deck it was derived from */
    public function getPrecedent()
    {
        return $this->precedents->first() ?: null;
    }

    /** @return PublishedDeck[] the decks it was derived from */
    public function getPrecedents(): array
    {
        return $this->precedents->toArray();
    }

    /** @param PublishedDeck[] $precedents */
    public function setPrecedents(array $precedents): void
    {
        $this->precedents->clear();
        foreach ($precedents as $precedent) {
            if ($precedent !== $this && !$this->precedents->contains($precedent)) {
                $this->precedents->add($precedent);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function setTournament(?TournamentInterface $tournament = null)
    {
        $this->tournament = $tournament;
    }

    /**
     * @inheritdoc
     */
    public function getTournament()
    {
        return $this->tournament;
    }

    /**
     * @inheritdoc
     */
    public function addFavorite(UserInterface $favorite)
    {
        $this->favorites->add($favorite);
    }

    /**
     * @inheritdoc
     */
    public function removeFavorite(UserInterface $favorite)
    {
        $this->favorites->removeElement($favorite);
    }

    /**
     * @inheritdoc
     */
    public function getFavorites()
    {
        return $this->favorites;
    }

    /**
     * @inheritdoc
     */
    public function addVote(UserInterface $vote)
    {
        $this->votes->add($vote);
    }

    /**
     * @inheritdoc
     */
    public function removeVote(UserInterface $vote)
    {
        $this->votes->removeElement($vote);
    }

    /**
     * @inheritdoc
     */
    public function getVotes()
    {
        return $this->votes;
    }

    /**
     * @inheritdoc
     */
    public function getTags(): ?string
    {
        return $this->tags;
    }

    public function setTags(?string $tags): void
    {
        $this->tags = $tags;
    }

    public function getLine(): ?DecklistLine
    {
        return $this->line;
    }

    public function setLine(DecklistLine $line): void
    {
        $this->line = $line;
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn;
    }

    public function setWithdrawn(bool $withdrawn): void
    {
        $this->withdrawn = $withdrawn;
    }

    public function getDatePublished(): ?\DateTimeInterface
    {
        return $this->datePublished;
    }

    public function setDatePublished(?\DateTimeInterface $datePublished): void
    {
        $this->datePublished = $datePublished;
    }

    /**
     * An archive deck, brought in from the archives or submitted for the historians: no
     * grace period and nothing locks, since historians correct them
     */
    public function isArchived(): bool
    {
        return $this->source !== null || $this->reviewStatus !== null;
    }

    /** Until when the author can withdraw it; null for archive decks */
    public function getGraceEnds(): ?\DateTimeInterface
    {
        if ($this->isArchived() || $this->datePublished === null) {
            return null;
        }
        return \DateTimeImmutable::createFromInterface($this->datePublished)->modify('+' . self::GRACE_HOURS . ' hours');
    }

    /** Public and still within the grace period, so it can be withdrawn */
    public function canWithdraw(): bool
    {
        $ends = $this->getGraceEnds();
        return !$this->withdrawn && $ends !== null && $ends > new \DateTimeImmutable();
    }

    /**
     * Whether the cards, half, format and date can change: only while withdrawn, or on an
     * archive deck, which historians correct
     */
    public function isContentEditable(): bool
    {
        return $this->withdrawn || $this->isArchived();
    }

    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return $this->version;
    }

    public function getPatch(): int
    {
        return $this->patch;
    }

    public function setPatch(int $patch): void
    {
        $this->patch = $patch;
    }

    /** How it's numbered almost everywhere: 2.0 (Ketura, 2026-11-08) */
    public function getVersionLabel(): string
    {
        return $this->version . '.0';
    }

    /** With its detail edits: 2.0.1 */
    public function getFullVersion(): string
    {
        return $this->getVersionLabel() . ($this->patch ? '.' . $this->patch : '');
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): mixed
    {
        $slots = $this->getSlots();
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'date_creation' => $this->getDateCreation()->format('c'),
            'date_update' => $this->getDateUpdate()->format('c'),
            'description_md' => $this->getDescriptionMd(),
            'user_id' => $this->getUser()->getId(),
            'slots' => $slots->getContent() ?: new stdClass(),
            'gemp' => $slots->toGempString(),
            'version' => $this->getVersion(),
            'version_label' => $this->getVersionLabel(),
            'full_version' => $this->getFullVersion(),
            'line' => $this->line?->getId(),
            'half' => $this->half,
            'tags' => $this->tags,
            'plays' => array_map(fn (PublishedDeckPlay $play) => $play->serialize(), $this->plays->toArray()),
            'legacy_author' => $this->source?->getLegacyAuthor(),
        ] + $this->lotrDeckJson();
    }
}
