<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a seeded decklist came from (phase3/06): the archive scrapes of
 * decktech.net and the Lothlorien board. Kept after a claim moves the decklist
 * to its author.
 */
#[ORM\Entity]
#[ORM\Table(name: 'published_deck_source')]
#[ORM\UniqueConstraint(name: 'published_deck_source_idx', columns: ['source', 'source_id'])]
class PublishedDeckSource
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: PublishedDeck::class, inversedBy: 'source')]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

    /** decktech, lothlorien, ... */
    #[ORM\Column(name: 'source', type: 'string', length: 30, nullable: false)]
    protected string $source;

    #[ORM\Column(name: 'source_id', type: 'string', length: 100, nullable: false)]
    protected string $sourceId;

    #[ORM\Column(name: 'source_url', type: 'string', length: 500, nullable: false)]
    protected string $sourceUrl;

    #[ORM\Column(name: 'archive_url', type: 'string', length: 500, nullable: true)]
    protected ?string $archiveUrl = null;

    /** The author's name on the source, shown until a registered user claims the deck */
    #[ORM\Column(name: 'legacy_author', type: 'string', length: 255, nullable: false)]
    protected string $legacyAuthor;

    #[ORM\Column(name: 'posted_date', type: 'date', nullable: true)]
    protected ?DateTimeInterface $postedDate = null;

    /** The batch's date as given, which may be partial ("2003-08", "2003") */
    #[ORM\Column(name: 'posted_date_text', type: 'string', length: 10, nullable: true)]
    protected ?string $postedDateText = null;

    #[ORM\Column(name: 'format_as_written', type: 'string', length: 100, nullable: true)]
    protected ?string $formatAsWritten = null;

    #[ORM\Column(name: 'raw_decklist', type: 'text', nullable: false)]
    protected string $rawDecklist = '';

    /** Lines the scraper couldn't map: [{line, count, section, reason}] */
    #[ORM\Column(name: 'unresolved', type: 'json', nullable: false)]
    protected array $unresolved = [];

    /** {score, votes, scale} as the source had them */
    #[ORM\Column(name: 'ratings', type: 'json', nullable: true)]
    protected ?array $ratings = null;

    /** The thread's comments: [{author, date, text, format}] */
    #[ORM\Column(name: 'comments', type: 'json', nullable: false)]
    protected array $comments = [];

    /** When the batch gave no format: Red Book's guess (FormatGuesser): {format, reasons, alternatives} */
    #[ORM\Column(name: 'format_guess', type: 'json', nullable: true)]
    protected ?array $formatGuess = null;

    /**
     * The scraper's own account of the parse: {status, issues[], guessed[{line, card,
     * method}]}, the lines it matched by a loose rule among them
     */
    #[ORM\Column(name: 'parse', type: 'json', nullable: true)]
    protected ?array $parse = null;

    /**
     * Other archive decks the scraper found to be the same list or close to it:
     * [{source, sourceId, relation, similarity, sameAuthor}]; for merging decks later
     */
    #[ORM\Column(name: 'related', type: 'json', nullable: true)]
    protected ?array $related = null;

    /** The batch's generator line, for tracing a bad import */
    #[ORM\Column(name: 'batch', type: 'string', length: 255, nullable: true)]
    protected ?string $batch = null;

    public function __construct(PublishedDeck $decklist, string $source, string $sourceId)
    {
        $this->decklist = $decklist;
        $this->source = $source;
        $this->sourceId = $sourceId;
    }

    public function getDecklist(): PublishedDeck { return $this->decklist; }
    public function getSource(): string { return $this->source; }
    public function getSourceId(): string { return $this->sourceId; }
    public function getSourceUrl(): string { return $this->sourceUrl; }
    public function setSourceUrl(string $url): void { $this->sourceUrl = $url; }
    public function getArchiveUrl(): ?string { return $this->archiveUrl; }
    public function setArchiveUrl(?string $url): void { $this->archiveUrl = $url; }
    public function getLegacyAuthor(): string { return $this->legacyAuthor; }
    public function setLegacyAuthor(string $author): void { $this->legacyAuthor = $author; }
    public function getPostedDate(): ?DateTimeInterface { return $this->postedDate; }
    public function setPostedDate(?DateTimeInterface $date): void { $this->postedDate = $date; }
    public function getPostedDateText(): ?string { return $this->postedDateText; }
    public function setPostedDateText(?string $text): void { $this->postedDateText = $text; }
    public function getFormatAsWritten(): ?string { return $this->formatAsWritten; }
    public function setFormatAsWritten(?string $format): void { $this->formatAsWritten = $format; }
    public function getRawDecklist(): string { return $this->rawDecklist; }
    public function setRawDecklist(string $raw): void { $this->rawDecklist = $raw; }
    public function getUnresolved(): array { return $this->unresolved; }
    public function setUnresolved(array $unresolved): void { $this->unresolved = $unresolved; }
    public function getRatings(): ?array { return $this->ratings; }
    public function setRatings(?array $ratings): void { $this->ratings = $ratings; }
    public function getComments(): array { return $this->comments; }
    public function setComments(array $comments): void { $this->comments = $comments; }
    public function getParse(): ?array { return $this->parse; }
    public function setParse(?array $parse): void { $this->parse = $parse ?: null; }
    public function getRelated(): array { return $this->related ?? []; }
    public function setRelated(?array $related): void { $this->related = $related ?: null; }
    public function getFormatGuess(): ?array { return $this->formatGuess; }
    public function setFormatGuess(?array $guess): void { $this->formatGuess = $guess; }
    public function getBatch(): ?string { return $this->batch; }
    public function setBatch(?string $batch): void { $this->batch = $batch; }
}
