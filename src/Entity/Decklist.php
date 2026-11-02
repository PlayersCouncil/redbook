<?php

namespace App\Entity;

use App\Repository\DecklistRepository;
use App\Classes\SlotCollectionDecorator;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Gedmo\Mapping\Annotation as Gedmo;
use JsonSerializable;
use Doctrine\ORM\Mapping as ORM;
use stdClass;

#[ORM\Entity(repositoryClass: DecklistRepository::class)]
#[ORM\Table(name: 'decklist')]
#[ORM\Index(name: 'decklist_format_idx', columns: ['target_format', 'completeness', 'half'])]
class Decklist extends CommonDeck implements JsonSerializable, DecklistInterface
{
    use LotrDeckTrait;

    /** Free Peoples or Shadow only (decision 0023); null for a whole deck */
    #[ORM\Column(name: 'half', type: 'string', length: 12, nullable: true)]
    protected ?string $half = null;

    /** @var Collection<int, DecklistCulture> */
    #[ORM\OneToMany(targetEntity: DecklistCulture::class, mappedBy: 'decklist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $cultures;

    /** @var Collection<int, DecklistPlay> */
    #[ORM\OneToMany(targetEntity: DecklistPlay::class, mappedBy: 'decklist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['eventDate' => 'DESC'])]
    protected $plays;

    /** Set for decklists seeded from the archive (phase3/06) */
    #[ORM\OneToOne(targetEntity: DecklistSource::class, mappedBy: 'decklist', cascade: ['persist', 'remove'])]
    protected ?DecklistSource $source = null;

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
     * @var int
     */
    #[ORM\Column(name: 'nb_favorites', type: 'integer')]
    protected $nbFavorites;

    /**
     * @var int
     */
    #[ORM\Column(name: 'nb_comments', type: 'integer')]
    protected $nbComments;

    /**
     * @var string
     */
    #[ORM\Column(name: 'version', type: 'string', length: 8)]
    protected $version;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: Decklistslot::class, mappedBy: 'decklist', cascade: ['persist', 'remove'])]
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
    #[ORM\OneToMany(targetEntity: Decklist::class, mappedBy: 'precedent')]
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
     * @var DecklistInterface
     */
    #[ORM\ManyToOne(targetEntity: Decklist::class, inversedBy: 'successors')]
    #[ORM\JoinColumn(name: 'precedent_decklist_id', referencedColumnName: 'id')]
    protected $precedent;

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
    #[ORM\JoinTable(name: 'favorite', joinColumns: [new ORM\JoinColumn(name: 'decklist_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')])]
    protected $favorites;

    /**
     * @var Collection
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'votes')]
    #[ORM\JoinTable(name: 'vote', joinColumns: [new ORM\JoinColumn(name: 'decklist_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')])]
    protected $votes;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->slots = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->successors = new ArrayCollection();
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

    /** @return Collection<int, DecklistCulture> */
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
            $this->cultures->add(new DecklistCulture($this, $culture, $count));
        }
    }

    /** @return Collection<int, DecklistPlay> */
    public function getPlays(): Collection
    {
        return $this->plays;
    }

    public function addPlay(DecklistPlay $play): void
    {
        $play->setDecklist($this);
        $this->plays->add($play);
    }

    public function removePlay(DecklistPlay $play): void
    {
        $this->plays->removeElement($play);
    }

    public function getSource(): ?DecklistSource
    {
        return $this->source;
    }

    public function setSource(?DecklistSource $source): void
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
    public function addSlot(DecklistslotInterface $slot)
    {
        $this->slots->add($slot);
    }

    /**
     * @inheritdoc
     */
    public function removeSlot(DecklistslotInterface $slot)
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
    public function addSuccessor(DecklistInterface $successor)
    {
        $this->successors->add($successor);
    }

    /**
     * @inheritdoc
     */
    public function removeSuccessor(DecklistInterface $successor)
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
     * @inheritdoc
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @inheritdoc
     */
    public function setPrecedent(?DecklistInterface $precedent = null)
    {
        $this->precedent = $precedent;
    }

    /**
     * @inheritdoc
     */
    public function getPrecedent()
    {
        return $this->precedent;
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
            'half' => $this->half,
            'plays' => array_map(fn (DecklistPlay $play) => $play->serialize(), $this->plays->toArray()),
            'legacy_author' => $this->source?->getLegacyAuthor(),
        ] + $this->lotrDeckJson();
    }
}
