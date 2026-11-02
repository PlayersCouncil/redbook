<?php

namespace App\Entity;

use App\Repository\PackRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @package App\Entity
 */
#[ORM\Entity(repositoryClass: PackRepository::class)]
#[ORM\Table(name: 'pack')]
#[ORM\UniqueConstraint(name: 'pack_code_idx', columns: ['code'])]
class Pack implements PackInterface
{
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
    #[ORM\Column(name: 'code', type: 'string', length: 255, nullable: false)]
    protected $code;

    /**
     * @var string
     */
    #[ORM\Column(name: 'name', type: 'string', length: 1024, nullable: false)]
    protected $name;

    /**
     * @var int
     */
    #[ORM\Column(name: 'position', type: 'smallint', nullable: false)]
    protected $position;

    /**
     * @var int
     */
    #[ORM\Column(name: 'size', type: 'smallint', nullable: false)]
    protected $size;

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
     * @var DateTime|null
     */
    #[ORM\Column(name: 'date_release', type: 'date', nullable: true)]
    protected $dateRelease;

    /** decipher, pc (V-sets) or pc-errata (sets 50-69, hidden from set browsing) */
    #[ORM\Column(name: 'kind', type: 'string', length: 20, nullable: false, options: ['default' => 'decipher'])]
    protected string $kind = 'decipher';

    /** The wiki's set ID (1, V1...); null for the PC errata sets */
    #[ORM\Column(name: 'wiki_set', type: 'string', length: 10, nullable: true)]
    protected ?string $wikiSet = null;

    #[ORM\Column(name: 'abbreviation', type: 'string', length: 20, nullable: true)]
    protected ?string $abbreviation = null;

    /**
     * @var int|null
     */
    #[ORM\Column(name: 'work_in_progress', type: 'boolean', nullable: false, options: ['default' => false])]
    protected bool $workInProgress = false;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'pack')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected $cards;

    /**
     * @var CycleInterface
     */
    #[ORM\ManyToOne(targetEntity: Cycle::class, inversedBy: 'packs')]
    #[ORM\JoinColumn(name: 'cycle_id', referencedColumnName: 'id')]
    protected $cycle;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->cards = new ArrayCollection();
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
    public function setCode($code)
    {
        $this->code = $code;
    }

    /**
     * @inheritdoc
     */
    public function getCode()
    {
        return $this->code;
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
    public function setPosition($position)
    {
        $this->position = $position;
    }

    /**
     * @inheritdoc
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * @inheritdoc
     */
    public function setSize($size)
    {
        $this->size = $size;
    }

    /**
     * @inheritdoc
     */
    public function getSize()
    {
        return $this->size;
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
    public function setDateRelease($dateRelease)
    {
        $this->dateRelease = $dateRelease;
    }

    /**
     * @inheritdoc
     */
    public function getDateRelease()
    {
        return $this->dateRelease;
    }


    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): void { $this->kind = $kind; }
    public function getWikiSet(): ?string { return $this->wikiSet; }
    public function setWikiSet(?string $wikiSet): void { $this->wikiSet = $wikiSet; }
    public function getAbbreviation(): ?string { return $this->abbreviation; }
    public function setAbbreviation(?string $abbreviation): void { $this->abbreviation = $abbreviation; }

    /**
     * @inheritdoc
     */
    public function addCard(CardInterface $card)
    {
        $this->cards->add($card);
    }

    /**
     * @inheritdoc
     */
    public function removeCard(CardInterface $card)
    {
        $this->cards->removeElement($card);
    }

    /**
     * @inheritdoc
     */
    public function getCards()
    {
        return $this->cards;
    }

    /**
     * @inheritdoc
     */
    public function setCycle(?CycleInterface $cycle = null)
    {
        $this->cycle = $cycle;
    }

    /**
     * @inheritdoc
     */
    public function getCycle()
    {
        return $this->cycle;
    }

    public function getWorkInProgress(): bool
    {
        return $this->workInProgress;
    }

    public function setWorkInProgress(bool $workInProgress): void
    {
        $this->workInProgress = $workInProgress;
    }

    /**
     * @inheritdoc
     */
    public function serialize()
    {
        return [
            'code' => $this->code,
            'cycle_code' => $this->cycle ? $this->cycle->getCode() : null,
            'date_release' => $this->dateRelease ? $this->dateRelease->format('Y-m-d') : null,
            'name' => $this->name,
            'position' => $this->position,
            'size' => $this->size,
            'kind' => $this->kind,
            'work_in_progress' => $this->workInProgress
        ];
    }

    /**
     * PHP 8.1+ uses these instead of the Serializable methods.
     * @return array
     */
    public function __serialize(): array
    {
        return $this->serialize();
    }

    public function __unserialize(array $data): void
    {
        $this->unserialize($data);
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function unserialize($serialized)
    {
        throw new Exception("unserialize() method unsupported");
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name ?: '';
    }
}
