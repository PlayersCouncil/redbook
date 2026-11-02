<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A registered user's request to own a seeded decklist (phase3/06). An admin
 * approves or rejects it; approval moves the decklist to the user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decklist_claim')]
#[ORM\Index(name: 'decklist_claim_status_idx', columns: ['status'])]
class DecklistClaim
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Decklist::class)]
    #[ORM\JoinColumn(name: 'decklist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Decklist $decklist;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    /** Why the user is the author: "I posted as Gandalf99 on decktech" */
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

    public function __construct(Decklist $decklist, User $user, ?string $message)
    {
        $this->decklist = $decklist;
        $this->user = $user;
        $this->message = $message;
        $this->dateCreation = new DateTime();
    }

    public function getId() { return $this->id; }
    public function getDecklist(): Decklist { return $this->decklist; }
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
