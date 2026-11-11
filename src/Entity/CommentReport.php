<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's report of a comment to the moderators (Ketura, 2026-11-11): why, and when. Kept
 * for the moderators to work through; telling them comes later. One open report per user per
 * comment: reporting again updates the reason.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment_report')]
#[ORM\UniqueConstraint(name: 'comment_report_user', columns: ['comment_id', 'user_id'])]
#[ORM\Index(name: 'comment_report_open_idx', columns: ['date_resolved', 'date_creation'])]
class CommentReport
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Comment::class)]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Comment $comment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    #[ORM\Column(name: 'reason', type: 'text')]
    protected string $reason;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    /** When a moderator dealt with it, and who */
    #[ORM\Column(name: 'date_resolved', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $dateResolved = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $resolvedBy = null;

    public function __construct(Comment $comment, User $user, string $reason)
    {
        $this->comment = $comment;
        $this->user = $user;
        $this->reason = $reason;
        $this->dateCreation = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getComment(): Comment { return $this->comment; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): void { $this->user = $user; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): void { $this->reason = $reason; $this->dateCreation = new DateTime(); }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
    public function getDateResolved(): ?DateTimeInterface { return $this->dateResolved; }
    public function getResolvedBy(): ?User { return $this->resolvedBy; }
}
