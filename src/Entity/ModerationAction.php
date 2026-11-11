<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something done to what someone else made (Ketura, 2026-11-11): deleting another's comment
 * or decklist. Who did it, why (a reason is required), and enough of what it was to know it
 * again once it's gone: its kind and id, its owner, its name or opening text.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_action')]
#[ORM\Index(name: 'moderation_action_subject_idx', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'moderation_action_date_idx', columns: ['date_creation'])]
class ModerationAction
{
    public const COMMENT_DELETE = 'comment.delete';
    public const DECKLIST_DELETE = 'decklist.delete';

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    /** Who did it */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $actor;

    #[ORM\Column(name: 'action', type: 'string', length: 40)]
    protected string $action;

    /** What it was done to: comment, decklist; and its id (which may no longer exist) */
    #[ORM\Column(name: 'subject_type', type: 'string', length: 20)]
    protected string $subjectType;

    #[ORM\Column(name: 'subject_id', type: 'integer')]
    protected int $subjectId;

    /** Whose it was */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $owner;

    /** Its name, or a comment's opening text */
    #[ORM\Column(name: 'summary', type: 'string', length: 255)]
    protected string $summary;

    #[ORM\Column(name: 'reason', type: 'text')]
    protected string $reason;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    public function __construct(?User $actor, string $action, string $subjectType, int $subjectId, ?User $owner, string $summary, string $reason)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->owner = $owner;
        $this->summary = mb_substr($summary, 0, 255);
        $this->reason = $reason;
        $this->dateCreation = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getActor(): ?User { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): int { return $this->subjectId; }
    public function getOwner(): ?User { return $this->owner; }
    public function getSummary(): string { return $this->summary; }
    public function getReason(): string { return $this->reason; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
}
