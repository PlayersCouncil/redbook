<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * An edit to a published decklist's details (Ketura, 2026-11-07; numbered 2026-11-08): the
 * patch it made (2.0.1), what it changed, who made it, and the name and description as
 * they were before, which are kept. The decklist's History tab lists them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'published_deck_revision')]
class PublishedDeckRevision
{
    /** The details an edit can change, each of which makes a new patch (the thumbnail too: Ketura, 2026-11-08) */
    public const PARTS = ['name', 'description', 'tags', 'bid', 'starting', 'starting_notes', 'annotations', 'precedent', 'thumbnail'];

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PublishedDeck::class)]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    protected string $name;

    #[ORM\Column(name: 'description_md', type: 'text', nullable: true)]
    protected ?string $descriptionMd;

    /** When this text was replaced */
    #[ORM\Column(name: 'date_replaced', type: 'datetime')]
    protected DateTimeInterface $dateReplaced;

    /** The patch the edit made: 1 for 2.0.1; null for edits recorded before patches were numbered */
    #[ORM\Column(name: 'patch', type: 'integer', nullable: true)]
    protected ?int $patch = null;

    /** @var string[]|null the parts it changed (PARTS) */
    #[ORM\Column(name: 'changed', type: 'json', nullable: true)]
    protected ?array $changed = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'editor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $editor = null;

    public function __construct(PublishedDeck $decklist)
    {
        $this->decklist = $decklist;
        $this->name = (string) $decklist->getName();
        $this->descriptionMd = $decklist->getDescriptionMd();
        $this->dateReplaced = new DateTime();
    }

    /** What the edit can change, for comparing before and after */
    public static function snapshot(PublishedDeck $decklist): array
    {
        return [
            'name' => (string) $decklist->getName(),
            'description' => trim((string) $decklist->getDescriptionMd()),
            'tags' => trim((string) $decklist->getTags()),
            'bid' => (string) $decklist->getStartingBid(),
            'starting' => json_encode(array_values($decklist->getStarting())),
            'starting_notes' => (string) $decklist->getStartingNotes(),
            'annotations' => json_encode($decklist->getAnnotations() ?: null),
            'precedent' => (string) $decklist->getPrecedent()?->getId(),
            'thumbnail' => json_encode([$decklist->getThumbnailCard()?->getCode(), $decklist->getThumbnailCrop()]),
        ];
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

    public function getPatch(): ?int
    {
        return $this->patch;
    }

    public function setPatch(?int $patch): void
    {
        $this->patch = $patch;
    }

    public function getChanged(): ?array
    {
        return $this->changed;
    }

    public function setChanged(?array $changed): void
    {
        $this->changed = $changed;
    }

    public function getEditor(): ?User
    {
        return $this->editor;
    }

    public function setEditor(?User $editor): void
    {
        $this->editor = $editor;
    }
}
