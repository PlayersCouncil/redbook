<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment's text as it was at one time (Ketura, 2026-11-11), for its History: the first is
 * as it was posted, and each edit adds one. Who wrote it: the author, or the moderator who
 * edited it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment_revision')]
#[ORM\Index(name: 'comment_revision_comment_idx', columns: ['comment_id', 'id'])]
class CommentRevision
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Comment::class)]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Comment $comment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $user;

    /** null for the text of a comment from before markdown was kept */
    #[ORM\Column(name: 'text_md', type: 'text', nullable: true)]
    protected ?string $textMd;

    #[ORM\Column(name: 'text', type: 'text')]
    protected string $text;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    public function __construct(Comment $comment, ?User $user, ?string $textMd, string $html, ?DateTimeInterface $date = null)
    {
        $this->comment = $comment;
        $this->user = $user;
        $this->textMd = $textMd;
        $this->text = $html;
        $this->dateCreation = $date ?? new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getComment(): Comment { return $this->comment; }
    public function getUser(): ?User { return $this->user; }
    public function getTextMd(): ?string { return $this->textMd; }
    public function getText(): string { return $this->text; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
}
