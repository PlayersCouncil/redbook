<?php

namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @package App\Entity
 */
#[ORM\Entity]
#[ORM\Table(name: 'deck_revision')]
class DeckRevision implements DeckRevisionInterface
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    /**
     * @var DateTime
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: false)]
    protected $dateCreation;

    /**
     * @var string
     */
    #[ORM\Column(name: 'variation', type: 'string', length: 1024)]
    protected $variation;

    /**
     * @var bool
     */
    #[ORM\Column(name: 'is_saved', type: 'boolean')]
    protected $isSaved;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'version', type: 'string', length: 8, nullable: true)]
    protected $version;

    /**
     * @var DeckInterface
     */
    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'changes')]
    #[ORM\JoinColumn(name: 'deck_id', referencedColumnName: 'id')]
    protected $deck;

    /**
     * A historian's edit of someone else's deck (Ketura, 2026-11-08): who made it, and its
     * summary, {changed: [cards, name, description, ...], synced: bool}, synced when the
     * archive decklist took the edit too. The History tab shows them.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'editor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $editor = null;

    #[ORM\Column(name: 'edit_summary', type: 'json', nullable: true)]
    protected ?array $editSummary = null;

    public function getEditor(): ?User
    {
        return $this->editor;
    }

    public function setEditor(?User $editor): void
    {
        $this->editor = $editor;
    }

    public function getEditSummary(): ?array
    {
        return $this->editSummary;
    }

    public function setEditSummary(?array $summary): void
    {
        $this->editSummary = $summary;
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
    public function setVariation($variation)
    {
        $this->variation = $variation;
    }

    /**
     * @inheritdoc
     */
    public function getVariation()
    {
        return $this->variation;
    }

    /**
     * @inheritdoc
     */
    public function setIsSaved($isSaved)
    {
        $this->isSaved = $isSaved;
    }

    /**
     * @inheritdoc
     */
    public function getIsSaved()
    {
        return $this->isSaved;
    }

    /**
     * @inheritdoc
     */
    public function setDeck(?DeckInterface $deck = null)
    {
        $this->deck = $deck;
    }

    /**
     * @inheritdoc
     */
    public function getDeck()
    {
        return $this->deck;
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
}
