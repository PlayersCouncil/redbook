<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * One saved state of a library (Ketura, 2026-11-09): what it held after a change, who made the
 * change and how, and what changed from the state before. Reverting restores a snapshot as a
 * new revision, so a revert can itself be undone.
 *
 * The snapshot is the library's contents as data, not a copy of its rows: name, description,
 * notes, tags, thumbnail, and the decklists in order with their commentary. Who can see the
 * library isn't part of it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'library_revision')]
#[ORM\Index(name: 'library_revision_library_idx', columns: ['library_id', 'id'])]
class LibraryRevision
{
    /** Edited in the library editor */
    public const EDIT = 'edit';
    /** Decklists added from a decklist's Add to Library */
    public const ADD = 'add';
    /** Made, with what it was made with */
    public const CREATE = 'create';
    /** Made official: the copy of a user's library */
    public const PROMOTE = 'promote';
    /** Refreshed from the user's library it was copied from */
    public const REFRESH = 'refresh';
    /** Imported, or imported again, from a seed manifest */
    public const SEED = 'seed';
    /** An earlier revision restored */
    public const REVERT = 'revert';
    /** As it was before its first recorded change (libraries made before there was history) */
    public const BASELINE = 'baseline';

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Library::class)]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Library $library;

    /** Who made the change; none for an import or the baseline */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $user = null;

    #[ORM\Column(name: 'kind', type: 'string', length: 20)]
    protected string $kind;

    /**
     * @var array{name: string, description: string, notes: string, tags: string, thumbnail: array, entries: array<array{decklist: int, annotation: string}>}
     */
    #[ORM\Column(name: 'snapshot', type: 'json')]
    protected array $snapshot;

    /**
     * What changed from the revision before (LibraryManager::diff), and for a revert the
     * revision restored and the decklists it couldn't bring back
     */
    #[ORM\Column(name: 'changes', type: 'json', nullable: true)]
    protected ?array $changes = null;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    public function __construct(Library $library, ?User $user, string $kind, array $snapshot, ?array $changes)
    {
        $this->library = $library;
        $this->user = $user;
        $this->kind = $kind;
        $this->snapshot = $snapshot;
        $this->changes = $changes;
        $this->dateCreation = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getLibrary(): Library { return $this->library; }
    public function getUser(): ?User { return $this->user; }
    public function getKind(): string { return $this->kind; }
    public function getSnapshot(): array { return $this->snapshot; }
    public function getChanges(): ?array { return $this->changes; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
    public function setDateCreation(DateTimeInterface $date): void { $this->dateCreation = $date; }
}
