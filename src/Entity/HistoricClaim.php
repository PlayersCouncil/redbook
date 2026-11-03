<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A request to claim a historic account (Ketura, 2026-11-02): a registered user says
 * "that archive author is me", an admin approves, and the historic account is merged
 * into theirs, decks, published decks and name (as an alias) together.
 */
#[ORM\Entity]
#[ORM\Table(name: 'historic_claim')]
#[ORM\Index(name: 'historic_claim_status_idx', columns: ['status'])]
class HistoricClaim
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /** The historic account being claimed */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'historic_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $historicUser;

    /** The person claiming it */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    #[ORM\Column(name: 'message', type: 'text', nullable: true)]
    protected ?string $message = null;

    #[ORM\Column(name: 'status', type: 'string', length: 10, nullable: false)]
    protected string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'date_creation', type: 'datetime', nullable: false)]
    protected DateTimeInterface $dateCreation;

    #[ORM\Column(name: 'date_decision', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $dateDecision = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $decidedBy = null;

    public function __construct(User $historicUser, User $user, ?string $message)
    {
        $this->historicUser = $historicUser;
        $this->user = $user;
        $this->message = $message;
        $this->dateCreation = new DateTime();
    }

    public function getId() { return $this->id; }
    public function getHistoricUser(): User { return $this->historicUser; }
    public function getUser(): User { return $this->user; }
    public function getMessage(): ?string { return $this->message; }
    public function getStatus(): string { return $this->status; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
    public function getDateDecision(): ?DateTimeInterface { return $this->dateDecision; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }

    public function decide(string $status, User $admin): void
    {
        $this->status = $status;
        $this->decidedBy = $admin;
        $this->dateDecision = new DateTime();
    }
}
