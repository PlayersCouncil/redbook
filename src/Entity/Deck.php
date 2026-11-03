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
class Deck extends CommonDeck implements DeckInterface
{
    use LotrDeckTrait;

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

        foreach ($savedChanges as $change) {
            $variation = json_decode($change->getVariation(), true);

            $row = [
                'variation' => $variation,
                'is_saved' => $change->getIsSaved(),
                'version' => $change->getVersion(),
                'content' => $preversion,
                'date_creation' => $change->getDateCreation()->format('c'),
            ];
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

        // add last know version with empty diff
        $row = [
            'variation' => null,
            'is_saved' => true,
            'version' => "0.0",
            'content' => $preversion,
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
