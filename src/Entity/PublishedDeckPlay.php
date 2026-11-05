<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * An event a decklist was played at (phase3/03). A decklist can have several;
 * each shows card versions as of its own date (decision 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'published_deck_play')]
#[ORM\Index(name: 'published_deck_play_date_idx', columns: ['event_date'])]
class PublishedDeckPlay
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: PublishedDeck::class, inversedBy: 'plays')]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

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

    /** Who played it there, when it wasn't the deck's author (v2 batches, Ketura 2026-11-04) */
    #[ORM\Column(name: 'pilot', type: 'string', length: 255, nullable: true)]
    protected ?string $pilot = null;

    /** The event (and its part) in the archive's event batch, for linking to its results later */
    #[ORM\Column(name: 'event_ref', type: 'string', length: 255, nullable: true)]
    protected ?string $eventRef = null;

    #[ORM\Column(name: 'part_ref', type: 'string', length: 255, nullable: true)]
    protected ?string $partRef = null;

    /** The pilot exactly as the event's results list them: what ties the deck to its placing */
    #[ORM\Column(name: 'result_player', type: 'string', length: 255, nullable: true)]
    protected ?string $resultPlayer = null;

    public function getId() { return $this->id; }
    public function getDecklist(): PublishedDeck { return $this->decklist; }
    public function setDecklist(PublishedDeck $decklist): void { $this->decklist = $decklist; }
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
    public function getPilot(): ?string { return $this->pilot; }
    public function setPilot(?string $pilot): void { $this->pilot = $pilot ? mb_substr($pilot, 0, 255) : null; }
    public function getEventRef(): ?string { return $this->eventRef; }
    public function setEventRef(?string $ref): void { $this->eventRef = $ref ? mb_substr($ref, 0, 255) : null; }
    public function getPartRef(): ?string { return $this->partRef; }
    public function setPartRef(?string $ref): void { $this->partRef = $ref ? mb_substr($ref, 0, 255) : null; }
    public function getResultPlayer(): ?string { return $this->resultPlayer; }
    public function setResultPlayer(?string $player): void { $this->resultPlayer = $player ? mb_substr($player, 0, 255) : null; }

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
            'pilot' => $this->pilot,
            'event_ref' => $this->eventRef,
            'part_ref' => $this->partRef,
        ];
    }
}
