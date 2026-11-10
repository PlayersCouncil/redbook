<?php

namespace App\Entity;

use App\Classes\SlotCollectionDecorator;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Ramsey\Uuid\UuidInterface;
use stdClass;

#[ORM\Entity]
#[ORM\Table(name: 'deck')]
class Deck extends CommonDeck implements DeckInterface, \App\Classes\ThumbnailSubject
{
    use LotrDeckTrait;

    /**
     * The card the deck's thumbnail shows (Ketura, 2026-11-09), chosen on the deckbuilder's Notes
     * tab; publishing starts the decklist's from it. Without one, a published deck's own, else
     * the deck's two main cultures (getThumbnailShown).
     */
    #[ORM\ManyToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(name: 'thumbnail_card_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Card $thumbnailCard = null;

    /** A framing of its own for the thumbnail card, as a decklist's (App\Classes\ThumbnailCrop) */
    #[ORM\Column(name: 'thumbnail_crop', type: 'json', nullable: true)]
    protected ?array $thumbnailCrop = null;

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

    /** Anyone with the link can view it, without it being published (decision 0023) */
    #[ORM\Column(name: 'shared', type: 'boolean', nullable: false, options: ['default' => false])]
    protected bool $shared = false;

    /**
     * How the deck came to be (Ketura, 2026-11-08), for the first line of its history:
     * {type: new|import|clone|decklist|archive|recreate|api, and what it came from}
     */
    #[ORM\Column(name: 'origin', type: 'json', nullable: true)]
    protected ?array $origin = null;

    /**
     * Deleted by its owner (Ketura, 2026-11-08): kept, out of sight, so a published decklist
     * can bring it back (Recreate)
     */
    #[ORM\Column(name: 'date_deleted', type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $dateDeleted = null;

    /**
     * Historical Deck Information (Ketura, 2026-11-02): null for an ordinary deck, else the
     * fields of a historic one (author, source, source_url, archive_url, event, placing,
     * players, date_used, date_posted, date_retrieved, format_as_written, notes). A stopgap
     * until historic accounts and the events table exist; date_used drives the effective date.
     */
    #[ORM\Column(name: 'historic', type: 'json', nullable: true)]
    protected ?array $historic = null;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'tags', type: 'string', length: 4000, nullable: true)]
    protected $tags;

    /**
     * @var int
     */
    #[ORM\Column(name: 'major_version', type: 'integer', nullable: false)]
    protected $majorVersion;

    /**
     * @var int
     */
    #[ORM\Column(name: 'minor_version', type: 'integer', nullable: false)]
    protected $minorVersion;

    /**
     * @var UuidInterface|null
     */
    #[ORM\Column(name: 'uuid', type: 'uuid', nullable: true, unique: true)]
    protected $uuid;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: DeckSlot::class, mappedBy: 'deck', cascade: ['persist', 'remove'])]
    protected $slots;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: PublishedDeck::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['dateCreation' => 'DESC'])]
    protected $children;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: DeckRevision::class, mappedBy: 'deck', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['dateCreation' => 'DESC', 'isSaved' => 'DESC'])]
    protected $changes;

    /**
     * @var UserInterface
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'decks')]
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
     * @var PublishedDeckInterface
     */
    #[ORM\ManyToOne(targetEntity: PublishedDeck::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_published_deck_id', referencedColumnName: 'id')]
    protected $parent;


    /**
     * @var DeckInterface
     */
    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'deckChildren')]
    #[ORM\JoinColumn(name: 'parent_deck_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    protected $parentDeck;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: Deck::class, mappedBy: 'parentDeck')]
    protected $deckChildren;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->slots = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->changes = new ArrayCollection();
        $this->deckChildren = new ArrayCollection();
        $this->minorVersion = 0;
        $this->majorVersion = 0;
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

    public function getOrigin(): ?array
    {
        return $this->origin;
    }

    public function setOrigin(?array $origin): void
    {
        $this->origin = $origin;
    }

    public function getDateDeleted(): ?\DateTimeInterface
    {
        return $this->dateDeleted;
    }

    public function setDateDeleted(?\DateTimeInterface $dateDeleted): void
    {
        $this->dateDeleted = $dateDeleted;
    }

    public function isDeleted(): bool
    {
        return $this->dateDeleted !== null;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): void
    {
        $this->shared = $shared;
    }

    public function getHistoric(): ?array
    {
        return $this->historic;
    }

    public function setHistoric(?array $historic): void
    {
        $this->historic = $historic;
    }

    public function isHistoric(): bool
    {
        return $this->historic !== null;
    }

    /**
     * @inheritdoc
     */
    public function setTags($tags)
    {
        $this->tags = $tags;
    }

    /**
     * @inheritdoc
     */
    public function getTags()
    {
        return $this->tags;
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
    public function addSlot(DeckSlotInterface $slot)
    {
        $this->slots->add($slot);
    }

    /**
     * @inheritdoc
     */
    public function removeSlot(DeckSlotInterface $slot)
    {
        $this->slots->removeElement($slot);
    }

    /**
     * @inheritdoc
     */
    public function addChild(PublishedDeckInterface $child)
    {
        $this->children->add($child);
    }

    /**
     * @inheritdoc
     */
    public function removeChild(PublishedDeckInterface $child)
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
    public function addDeckChild(DeckInterface $child)
    {
        $this->deckChildren->add($child);
    }

    /**
     * @inheritdoc
     */
    public function removeDeckChild(DeckInterface $child)
    {
        $this->deckChildren->removeElement($child);
    }

    /**
     * @inheritdoc
     */
    public function getDeckChildren()
    {
        return $this->deckChildren;
    }

    /**
     * @inheritdoc
     */
    public function addChange(DeckRevisionInterface $change)
    {
        $this->changes->add($change);
    }

    /**
     * @inheritdoc
     */
    public function removeChange(DeckRevisionInterface $change)
    {
        $this->changes->removeElement($change);
    }

    /**
     * @inheritdoc
     */
    public function getChanges()
    {
        return $this->changes;
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
    public function setParent(?PublishedDeckInterface $parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * @inheritdoc
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @inheritdoc
     */
    public function setParentDeck(?DeckInterface $parent = null)
    {
        $this->parentDeck = $parent;
    }

    /**
     * @inheritdoc
     */
    public function getParentDeck()
    {
        return $this->parentDeck;
    }

    /**
     * @inheritdoc
     */
    public function setMajorVersion($majorVersion)
    {
        $this->majorVersion = $majorVersion;
    }

    /**
     * @inheritdoc
     */
    public function getMajorVersion()
    {
        return $this->majorVersion;
    }

    /**
     * @inheritdoc
     */
    public function setMinorVersion($minorVersion)
    {
        $this->minorVersion = $minorVersion;
    }

    /**
     * @inheritdoc
     */
    public function getMinorVersion()
    {
        return $this->minorVersion;
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return $this->majorVersion . "." . $this->minorVersion;
    }

    /**
     * @inheritdoc
     */
    public function getHistory()
    {
        $slots = $this->getSlots();
        $cards = $slots->getContent();

        $snapshots = [];

        /*
         * All changes, with the newest at position 0
         */
        $changes = $this->getChanges();

        /*
         * Saved changes, with the newest at position 0
         * @var DeckRevisionInterface[] $savedChanges
         */
        $savedChanges = [];

        /*
         * Unsaved changes, with the oldest at position 0
         * @var DeckRevisionInterface[] $unsavedChanges
         */
        $unsavedChanges = [];

        foreach ($changes as $change) {
            if ($change->getIsSaved()) {
                array_push($savedChanges, $change);
            } else {
                array_unshift($unsavedChanges, $change);
            }
        }

        // recreating the versions with the variation info, starting from $preversion
        $preversion = $cards;
        // and the format each had, from the revisions that changed it (Ketura, 2026-11-08)
        $preformat = $this->getTargetFormat();

        foreach ($savedChanges as $change) {
            $variation = json_decode($change->getVariation(), true);

            $row = [
                'variation' => $variation,
                'is_saved' => $change->getIsSaved(),
                'version' => $change->getVersion(),
                'content' => $preversion,
                'format' => $preformat,
                'date_creation' => $change->getDateCreation()->format('c'),
            ];
            // a historian's edit says who, and what (Ketura, 2026-11-08)
            if ($change instanceof DeckRevision && $change->getEditSummary() !== null) {
                $row['editor'] = $change->getEditor()?->getDisplayName();
                $row['edit'] = $change->getEditSummary();
                if (isset($row['edit']['format'][0])) {
                    $preformat = $row['edit']['format'][0];
                }
            }
            array_unshift($snapshots, $row);

            // applying variation to create 'next' (older) preversion
            foreach ($variation[0] as $code => $qty) {
                if (!isset($preversion[$code])) {
                    continue;
                }
                $preversion[$code] = $preversion[$code] - $qty;
                if ($preversion[$code] == 0) {
                    unset($preversion[$code]);
                }
            }
            foreach ($variation[1] as $code => $qty) {
                if (!isset($preversion[$code])) {
                    $preversion[$code] = 0;
                }
                $preversion[$code] = $preversion[$code] + $qty;
            }
            ksort($preversion);
        }

        // the deck as it was made (Ketura, 2026-11-08: 0.1, the version a new deck starts at)
        $row = [
            'variation' => null,
            'is_saved' => true,
            // an archive deck came in as 1.0
            'version' => ($this->origin['type'] ?? ($this->user?->isHistoric() ? 'archive' : null)) === 'archive' ? '1.0' : '0.1',
            'content' => $preversion,
            'format' => $preformat,
            'date_creation' => $this->getDateCreation()->format('c')
        ];
        array_unshift($snapshots, $row);

        // recreating the snapshots with the variation info, starting from $postversion
        $postversion = $cards;
        foreach ($unsavedChanges as $change) {
            $variation = json_decode($change->getVariation(), true);
            $row = [
                'variation' => $variation,
                'is_saved' => $change->getIsSaved(),
                'version' => $change->getVersion(),
                'date_creation' => $change->getDateCreation()->format('c'),
            ];

            // applying variation to postversion
            foreach ($variation[0] as $code => $qty) {
                if (!isset($postversion[$code])) {
                    $postversion[$code] = 0;
                }
                $postversion[$code] = $postversion[$code] + $qty;
            }
            foreach ($variation[1] as $code => $qty) {
                if (!isset($preversion[$code])) {
                    continue;
                }
                $postversion[$code] = $postversion[$code] - $qty;
                if ($postversion[$code] == 0) {
                    unset($postversion[$code]);
                }
            }
            ksort($postversion);

            // add postversion with variation that lead to it
            $row['content'] = $postversion;
            array_push($snapshots, $row);
        }

        return $snapshots;
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

    public function getThumbnailCropFor(?Card $card): ?array
    {
        return $card && $this->thumbnailCrop && ($this->thumbnailCrop['code'] ?? null) === $card->getCode() ? $this->thumbnailCrop : null;
    }

    /** The draw deck's two main cultures, as a decklist's thumbnail picks them */
    public function getThumbnailCultures(): array
    {
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
        return PublishedDeck::pickThumbnailCultures($counts);
    }

    /**
     * What the deck's thumbnail shows (Ketura, 2026-11-09): its own card; else its latest
     * publication's thumbnail; else its cultures
     */
    public function getThumbnailShown(): \App\Classes\ThumbnailSubject
    {
        if ($this->thumbnailCard !== null) {
            return $this;
        }
        $latest = null;
        foreach ($this->getChildren() as $decklist) {
            if (!$decklist->isWithdrawn() && ($latest === null || $decklist->getVersion() > $latest->getVersion())) {
                $latest = $decklist;
            }
        }
        return $latest ?? $this;
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): mixed
    {
        $slots = $this->getSlots();
        return [
            'id' => $this->getId(),
            'uuid' => $this->getUuid(),
            'name' => $this->getName(),
            'date_creation' => $this->getDateCreation()->format('c'),
            'date_update' => $this->getDateUpdate()->format('c'),
            'description_md' => $this->getDescriptionMd(),
            'user_id' => $this->getUser()->getId(),
            'slots' => $slots->getContent() ?: new stdClass(),
            'gemp' => $slots->toGempString(),
            'version' => $this->getVersion(),
            'tags' => $this->getTags(),
            'shared' => $this->shared,
            'historic' => $this->historic,
            // its own thumbnail (Ketura, 2026-11-09): the card, and a framing for it
            'thumbnail' => $this->thumbnailCard?->getCode(),
            'thumbnail_crop' => $this->thumbnailCrop,
        ] + $this->lotrDeckJson();
    }

    /**
     * @inheritdoc
     */
    public function getIsUnsaved()
    {
        $changes = $this->getChanges();

        foreach ($changes as $change) {
            if (!$change->getIsSaved()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    /**
     * @inheritdoc
     */
    public function getUuid()
    {
        return $this->uuid;
    }
}
