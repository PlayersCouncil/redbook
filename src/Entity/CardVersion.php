<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * One English revision of a card, from the wiki via lotrtcg-card-data
 * (decision 0021). Revision 0 is the printed card; later revisions are errata
 * by their issuer. fields holds what the wiki records on that revision: a
 * field not listed is unchanged from the revision before.
 */
#[ORM\Entity]
#[ORM\Table(name: 'card_version')]
#[ORM\UniqueConstraint(name: 'card_version_wiki_idx', columns: ['wiki_id'])]
class CardVersion
{
    public const ISSUER_DECIPHER = 'Decipher';
    public const ISSUER_PC = "Player's Council";

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Card $card;

    #[ORM\Column(name: 'wiki_id', type: 'string', length: 30, nullable: false)]
    protected string $wikiId;

    #[ORM\Column(name: 'revision', type: 'smallint', nullable: false)]
    protected int $revision = 0;

    #[ORM\Column(name: 'issuer', type: 'string', length: 50, nullable: true)]
    protected ?string $issuer = null;

    #[ORM\Column(name: 'release_date', type: 'date', nullable: true)]
    protected ?DateTimeInterface $releaseDate = null;

    #[ORM\ManyToOne(targetEntity: ErrataBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?ErrataBatch $batch = null;

    #[ORM\Column(name: 'fields', type: 'json', nullable: false)]
    protected array $fields = [];

    #[ORM\Column(name: 'image_url', type: 'string', length: 255, nullable: true)]
    protected ?string $imageUrl = null;

    public function getId() { return $this->id; }
    public function getCard(): Card { return $this->card; }
    public function setCard(Card $card): void { $this->card = $card; }
    public function getWikiId(): string { return $this->wikiId; }
    public function setWikiId(string $wikiId): void { $this->wikiId = $wikiId; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $revision): void { $this->revision = $revision; }
    public function getIssuer(): ?string { return $this->issuer; }
    public function setIssuer(?string $issuer): void { $this->issuer = $issuer; }
    public function getReleaseDate(): ?DateTimeInterface { return $this->releaseDate; }
    public function setReleaseDate(?DateTimeInterface $releaseDate): void { $this->releaseDate = $releaseDate; }
    public function getBatch(): ?ErrataBatch { return $this->batch; }
    public function setBatch(?ErrataBatch $batch): void { $this->batch = $batch; }
    public function getFields(): array { return $this->fields; }
    public function setFields(array $fields): void { $this->fields = $fields; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl): void { $this->imageUrl = $imageUrl; }
}
