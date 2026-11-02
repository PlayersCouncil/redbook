<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * An event a decklist was played at (phase3/03). A decklist can have several;
 * each shows card versions as of its own date (decision 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'decklist_play')]
#[ORM\Index(name: 'decklist_play_date_idx', columns: ['event_date'])]
class DecklistPlay
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Decklist::class, inversedBy: 'plays')]
    #[ORM\JoinColumn(name: 'decklist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Decklist $decklist;

    #[ORM\Column(name: 'event_name', type: 'string', length: 255, nullable: false)]
    protected string $eventName = '';

    #[ORM\Column(name: 'event_date', type: 'date', nullable: true)]
    protected ?DateTimeInterface $eventDate = null;

    /** Relaxed, Formal, Premier, Other (ThronesDB's tournament tiers) */
    #[ORM\ManyToOne(targetEntity: Tournament::class)]
    #[ORM\JoinColumn(name: 'tier_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Tournament $tier = null;

    /** As given: "1st", "Top 8", "3-1" */
    #[ORM\Column(name: 'placing', type: 'string', length: 50, nullable: true)]
    protected ?string $placing = null;

    #[ORM\Column(name: 'player_count', type: 'smallint', nullable: true)]
    protected ?int $playerCount = null;

    /** Gemp format code, when the event's format differs from the decklist's */
    #[ORM\Column(name: 'format', type: 'string', length: 50, nullable: true)]
    protected ?string $format = null;

    #[ORM\Column(name: 'location', type: 'string', length: 255, nullable: true)]
    protected ?string $location = null;

    #[ORM\Column(name: 'url', type: 'string', length: 500, nullable: true)]
    protected ?string $url = null;

    public function getId() { return $this->id; }
    public function getDecklist(): Decklist { return $this->decklist; }
    public function setDecklist(Decklist $decklist): void { $this->decklist = $decklist; }
    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $eventName): void { $this->eventName = $eventName; }
    public function getEventDate(): ?DateTimeInterface { return $this->eventDate; }
    public function setEventDate(?DateTimeInterface $eventDate): void { $this->eventDate = $eventDate; }
    public function getTier(): ?Tournament { return $this->tier; }
    public function setTier(?Tournament $tier): void { $this->tier = $tier; }
    public function getPlacing(): ?string { return $this->placing; }
    public function setPlacing(?string $placing): void { $this->placing = $placing; }
    public function getPlayerCount(): ?int { return $this->playerCount; }
    public function setPlayerCount(?int $playerCount): void { $this->playerCount = $playerCount; }
    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): void { $this->format = $format; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): void { $this->location = $location; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): void { $this->url = $url; }

    public function serialize(): array
    {
        return [
            'event_name' => $this->eventName,
            'event_date' => $this->eventDate?->format('Y-m-d'),
            'tier' => $this->tier?->getDescription(),
            'placing' => $this->placing,
            'player_count' => $this->playerCount,
            'format' => $this->format,
            'location' => $this->location,
            'url' => $this->url,
        ];
    }
}
