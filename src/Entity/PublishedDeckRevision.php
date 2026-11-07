<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A decklist's name and description as they were before an edit (Ketura, 2026-11-07):
 * the commentary stays editable after the cards are fixed, but what it said is kept.
 */
#[ORM\Entity]
#[ORM\Table(name: 'published_deck_revision')]
class PublishedDeckRevision
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PublishedDeck::class)]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

    #[ORM\Column(name: 'name', type: 'string', length: 60)]
    protected string $name;

    #[ORM\Column(name: 'description_md', type: 'text', nullable: true)]
    protected ?string $descriptionMd;

    /** When this text was replaced */
    #[ORM\Column(name: 'date_replaced', type: 'datetime')]
    protected DateTimeInterface $dateReplaced;

    public function __construct(PublishedDeck $decklist)
    {
        $this->decklist = $decklist;
        $this->name = (string) $decklist->getName();
        $this->descriptionMd = $decklist->getDescriptionMd();
        $this->dateReplaced = new DateTime();
    }

    public function getDecklist(): PublishedDeck
    {
        return $this->decklist;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescriptionMd(): ?string
    {
        return $this->descriptionMd;
    }

    public function getDateReplaced(): DateTimeInterface
    {
        return $this->dateReplaced;
    }
}
