<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment on a decklist or a library (one table for both since Ketura, 2026-11-11; libraries'
 * were library_comment). A reply names the comment it answers, and the thread is drawn from
 * that (App\Services\CommentThreads).
 *
 * The text is kept as written (markdown) and as shown (html); comments from before 2026-11-11
 * have only the html. An edit keeps what it replaced (CommentRevision). Deleting keeps the row,
 * so replies stay where they were: a deleted comment shows as deleted while it has replies
 * still standing, and not at all once it hasn't.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'comment_parent_idx', columns: ['parent_id'])]
class Comment implements CommentInterface
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    /** As shown: rendered from the markdown */
    #[ORM\Column(name: 'text', type: 'text')]
    protected $text;

    /** As written; null for a comment from before these were kept */
    #[ORM\Column(name: 'text_md', type: 'text', nullable: true)]
    protected ?string $textMd = null;

    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: false)]
    protected $dateCreation;

    /** When its text last changed; null if it never has */
    #[ORM\Column(name: 'date_edited', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $dateEdited = null;

    /** Folded away by the owner of what it's on, or a moderator; anyone can open it */
    #[ORM\Column(name: 'is_hidden', type: 'boolean')]
    protected $isHidden = false;

    /** Deleted by its author, or by a moderator (deletedBy): its text is no longer shown */
    #[ORM\Column(name: 'date_deleted', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $dateDeleted = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $deletedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    protected $user;

    /** What it's on: a decklist, or a library */
    #[ORM\ManyToOne(targetEntity: PublishedDeck::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: true)]
    protected $decklist;

    #[ORM\ManyToOne(targetEntity: Library::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    protected ?Library $library = null;

    /** The comment it replies to; none for one on the decklist or library itself */
    #[ORM\ManyToOne(targetEntity: Comment::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Comment $parent = null;

    /** Cheers, and of those the fires (CommentReaction) */
    #[ORM\Column(name: 'nb_cheers', type: 'integer', options: ['default' => 0])]
    protected int $nbCheers = 0;

    #[ORM\Column(name: 'nb_fires', type: 'integer', options: ['default' => 0])]
    protected int $nbFires = 0;

    public function __construct()
    {
        $this->dateCreation = new DateTime();
    }

    public function setId($id) { $this->id = $id; }
    public function getId() { return $this->id; }
    public function setText($text) { $this->text = $text; }
    public function getText() { return $this->text; }
    public function getTextMd(): ?string { return $this->textMd; }
    public function setTextMd(?string $md): void { $this->textMd = $md; }
    public function setDateCreation($dateCreation) { $this->dateCreation = $dateCreation; }
    public function getDateCreation() { return $this->dateCreation; }
    public function getDateEdited(): ?DateTimeInterface { return $this->dateEdited; }
    public function setDateEdited(?DateTimeInterface $date): void { $this->dateEdited = $date; }
    public function setIsHidden($isHidden) { $this->isHidden = (bool) $isHidden; }
    public function getIsHidden() { return $this->isHidden; }
    public function setUser(?UserInterface $user = null) { $this->user = $user; }
    public function getUser() { return $this->user; }
    public function setDecklist(?PublishedDeckInterface $decklist = null) { $this->decklist = $decklist; }
    public function getDecklist() { return $this->decklist; }
    public function getLibrary(): ?Library { return $this->library; }
    public function setLibrary(?Library $library): void { $this->library = $library; }
    public function getParent(): ?Comment { return $this->parent; }
    public function setParent(?Comment $parent): void { $this->parent = $parent; }
    public function getNbCheers(): int { return $this->nbCheers; }
    public function setNbCheers(int $n): void { $this->nbCheers = max(0, $n); }
    public function getNbFires(): int { return $this->nbFires; }
    public function setNbFires(int $n): void { $this->nbFires = max(0, $n); }

    public function isDeleted(): bool { return $this->dateDeleted !== null; }
    public function getDateDeleted(): ?DateTimeInterface { return $this->dateDeleted; }
    public function getDeletedBy(): ?User { return $this->deletedBy; }

    /** By whoever deleted it: its author, or a moderator */
    public function delete(User $by): void
    {
        $this->dateDeleted = new DateTime();
        $this->deletedBy = $by;
    }

    /** Deleted by someone other than its author */
    public function isRemoved(): bool
    {
        return $this->isDeleted() && $this->deletedBy !== null && $this->deletedBy !== $this->user;
    }

    /** What it's on */
    public function getSubject(): PublishedDeck|Library|null
    {
        return $this->decklist ?? $this->library;
    }

    /** decklist or library: the subject's kind, as the comment forms post it */
    public function getSubjectKind(): string
    {
        return $this->library !== null ? 'library' : 'decklist';
    }
}
