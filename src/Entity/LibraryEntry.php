<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * One decklist in a library (Ketura, 2026-11-08): the exact version the curator picked,
 * its place in the library, and the curator's commentary on it
 */
#[ORM\Entity]
#[ORM\Table(name: 'library_entry')]
#[ORM\UniqueConstraint(name: 'library_entry_decklist', columns: ['library_id', 'published_deck_id'])]
class LibraryEntry
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Library::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Library $library;

    #[ORM\ManyToOne(targetEntity: PublishedDeck::class)]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

    #[ORM\Column(name: 'position', type: 'integer')]
    protected int $position = 0;

    #[ORM\Column(name: 'annotation_md', type: 'text', nullable: true)]
    protected ?string $annotationMd = null;

    #[ORM\Column(name: 'annotation_html', type: 'text', nullable: true)]
    protected ?string $annotationHtml = null;

    #[ORM\Column(name: 'date_added', type: 'datetime')]
    protected DateTimeInterface $dateAdded;

    public function __construct(PublishedDeck $decklist)
    {
        $this->decklist = $decklist;
        $this->dateAdded = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getLibrary(): Library { return $this->library; }
    public function setLibrary(Library $library): void { $this->library = $library; }
    public function getDecklist(): PublishedDeck { return $this->decklist; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; }
    public function getAnnotationMd(): ?string { return $this->annotationMd; }
    public function setAnnotationMd(?string $annotationMd): void { $this->annotationMd = $annotationMd; }
    public function getAnnotationHtml(): ?string { return $this->annotationHtml; }
    public function setAnnotationHtml(?string $annotationHtml): void { $this->annotationHtml = $annotationHtml; }
    public function getDateAdded(): DateTimeInterface { return $this->dateAdded; }

    /** Shown to everyone: the decklist is public */
    public function isAvailable(): bool
    {
        return !$this->decklist->isWithdrawn();
    }
}
