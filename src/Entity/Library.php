<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Library (Ketura, 2026-11-08): a collection of published decklists that belong together
 * (an event's decks, the starter decks, a sealed league's lists), each with the curator's
 * commentary, plus general notes and tags.
 *
 * No publishing paradigm: a library is private, hidden (shared by link) or public, and edits
 * show at once. It's addressed by its uuid while it's the owner's; the first time it goes
 * public it takes a public number, its address on /library/view, kept from then on.
 * A Librarian may edit any public library; "official" marks the PC's own (an admin page to
 * promote one comes later).
 */
#[ORM\Entity(repositoryClass: \App\Repository\LibraryRepository::class)]
#[ORM\Table(name: 'library')]
#[ORM\Index(name: 'library_visibility_idx', columns: ['visibility', 'date_update'])]
class Library implements \App\Classes\ThumbnailSubject
{
    public const PRIVATE = 'private';
    public const HIDDEN = 'hidden';
    public const PUBLIC = 'public';
    public const VISIBILITIES = [self::PRIVATE, self::HIDDEN, self::PUBLIC];

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(name: 'uuid', type: 'uuid', unique: true)]
    protected $uuid;

    /** Its address once it has been public: 1, 2, 3 in the order libraries first went public */
    #[ORM\Column(name: 'public_id', type: 'integer', nullable: true, unique: true)]
    protected ?int $publicId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    protected string $name = '';

    #[ORM\Column(name: 'name_canonical', type: 'string', length: 255)]
    protected string $nameCanonical = '';

    #[ORM\Column(name: 'description_md', type: 'text', nullable: true)]
    protected ?string $descriptionMd = null;

    #[ORM\Column(name: 'description_html', type: 'text', nullable: true)]
    protected ?string $descriptionHtml = null;

    /** The Notes tab's general notes */
    #[ORM\Column(name: 'notes_md', type: 'text', nullable: true)]
    protected ?string $notesMd = null;

    #[ORM\Column(name: 'notes_html', type: 'text', nullable: true)]
    protected ?string $notesHtml = null;

    /**
     * Its own thumbnail (Ketura, 2026-11-08/09): any card, picked by name and framed by hand or
     * by its template; or a banner of one or two cultures, in any combination; or neither, and
     * its first deck's
     */
    #[ORM\ManyToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(name: 'thumbnail_card_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Card $thumbnailCard = null;

    /** @var array{code: string, w: float, l: float, t: float}|null */
    #[ORM\Column(name: 'thumbnail_crop', type: 'json', nullable: true)]
    protected ?array $thumbnailCrop = null;

    /** @var array<array{code: string, name: string, side: string}>|null the banner's cultures, top left then bottom right */
    #[ORM\Column(name: 'thumbnail_cultures', type: 'json', nullable: true)]
    protected ?array $thumbnailCultures = null;

    #[ORM\Column(name: 'tags', type: 'string', length: 4000, nullable: true)]
    protected ?string $tags = null;

    #[ORM\Column(name: 'visibility', type: 'string', length: 10)]
    protected string $visibility = self::PRIVATE;

    #[ORM\Column(name: 'official', type: 'boolean', options: ['default' => false])]
    protected bool $official = false;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    #[ORM\Column(name: 'date_update', type: 'datetime')]
    protected DateTimeInterface $dateUpdate;

    /** When it first went public */
    #[ORM\Column(name: 'date_public', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $datePublic = null;

    /** @var Collection<int, LibraryEntry> */
    #[ORM\OneToMany(targetEntity: LibraryEntry::class, mappedBy: 'library', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $entries;

    /** Likes (Cheers), favourites and comments (Ketura, 2026-11-09), as a decklist has them */
    #[ORM\Column(name: 'nb_votes', type: 'integer', options: ['default' => 0])]
    protected int $nbVotes = 0;

    #[ORM\Column(name: 'nb_favorites', type: 'integer', options: ['default' => 0])]
    protected int $nbFavorites = 0;

    #[ORM\Column(name: 'nb_comments', type: 'integer', options: ['default' => 0])]
    protected int $nbComments = 0;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'library_vote', joinColumns: [new ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    protected Collection $votes;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'library_favorite', joinColumns: [new ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    protected Collection $favorites;

    /** @var Collection<int, LibraryComment> */
    #[ORM\OneToMany(targetEntity: LibraryComment::class, mappedBy: 'library', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dateCreation' => 'ASC'])]
    protected Collection $comments;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->votes = new ArrayCollection();
        $this->favorites = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->dateCreation = new DateTime();
        $this->dateUpdate = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getNbVotes(): int { return $this->nbVotes; }
    public function getNbFavorites(): int { return $this->nbFavorites; }
    public function getNbComments(): int { return $this->nbComments; }
    /** @return Collection<int, User> */
    public function getVotes(): Collection { return $this->votes; }
    /** @return Collection<int, User> */
    public function getFavorites(): Collection { return $this->favorites; }
    /** @return Collection<int, LibraryComment> */
    public function getComments(): Collection { return $this->comments; }

    /** Like or unlike; true if the user likes it now */
    public function toggleVote(User $user): bool
    {
        return $this->toggle($this->votes, $user, $this->nbVotes);
    }

    /** Favourite or unfavourite; true if it is the user's favourite now */
    public function toggleFavorite(User $user): bool
    {
        return $this->toggle($this->favorites, $user, $this->nbFavorites);
    }

    /** @param Collection<int, User> $who */
    private function toggle(Collection $who, User $user, int &$count): bool
    {
        if ($who->contains($user)) {
            $who->removeElement($user);
            $count = max(0, $count - 1);
            return false;
        }
        $who->add($user);
        $count++;
        return true;
    }

    public function addComment(LibraryComment $comment): void
    {
        $comment->setLibrary($this);
        $this->comments->add($comment);
        $this->nbComments++;
    }
    public function getUuid() { return $this->uuid; }
    public function setUuid($uuid): void { $this->uuid = $uuid; }
    public function getPublicId(): ?int { return $this->publicId; }
    public function setPublicId(?int $publicId): void { $this->publicId = $publicId; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): void { $this->user = $user; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getNameCanonical(): string { return $this->nameCanonical; }
    public function setNameCanonical(string $nameCanonical): void { $this->nameCanonical = $nameCanonical; }
    public function getDescriptionMd(): ?string { return $this->descriptionMd; }
    public function setDescriptionMd(?string $descriptionMd): void { $this->descriptionMd = $descriptionMd; }
    public function getDescriptionHtml(): ?string { return $this->descriptionHtml; }
    public function setDescriptionHtml(?string $descriptionHtml): void { $this->descriptionHtml = $descriptionHtml; }
    public function getNotesMd(): ?string { return $this->notesMd; }
    public function setNotesMd(?string $notesMd): void { $this->notesMd = $notesMd; }
    public function getNotesHtml(): ?string { return $this->notesHtml; }
    public function setNotesHtml(?string $notesHtml): void { $this->notesHtml = $notesHtml; }
    /** The card it was given, whatever it shows */
    public function getOwnThumbnailCard(): ?Card { return $this->thumbnailCard; }
    public function setThumbnailCard(?Card $card): void { $this->thumbnailCard = $card; }
    public function getThumbnailCrop(): ?array { return $this->thumbnailCrop; }
    public function setThumbnailCrop(?array $crop): void { $this->thumbnailCrop = $crop; }
    /** @return array<array{code: string, name: string, side: string}> */
    public function getOwnThumbnailCultures(): array { return $this->thumbnailCultures ?? []; }
    public function setThumbnailCultures(?array $cultures): void { $this->thumbnailCultures = $cultures ?: null; }

    /** card, cultures, or auto: its first deck's */
    public function getThumbnailMode(): string
    {
        return $this->thumbnailCultures ? 'cultures' : ($this->thumbnailCard ? 'card' : 'auto');
    }

    public function getThumbnailCard(): ?Card
    {
        return match ($this->getThumbnailMode()) {
            'card' => $this->thumbnailCard,
            'cultures' => null,
            default => $this->getFirstAvailableDecklist()?->getThumbnailCard(),
        };
    }

    public function getThumbnailCropFor(?Card $card): ?array
    {
        if ($this->getThumbnailMode() === 'card') {
            return $card && ($this->thumbnailCrop['code'] ?? null) === $card->getCode() ? $this->thumbnailCrop : null;
        }
        return $this->getFirstAvailableDecklist()?->getThumbnailCropFor($card);
    }

    /** Its own banner: one culture fills the tile, two share it on the diagonal */
    public function getThumbnailCultures(): array
    {
        $own = $this->getOwnThumbnailCultures();
        if ($own) {
            $corners = count($own) === 1 ? ['full'] : ['fp', 'shadow'];
            return array_map(fn ($c, $corner) => $c + ['corner' => $corner], array_slice($own, 0, 2), $corners);
        }
        return $this->getThumbnailMode() === 'card' ? [] : ($this->getFirstAvailableDecklist()?->getThumbnailCultures() ?? []);
    }
    public function getTags(): ?string { return $this->tags; }
    public function setTags(?string $tags): void { $this->tags = $tags; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): void { $this->visibility = in_array($visibility, self::VISIBILITIES, true) ? $visibility : self::PRIVATE; }
    public function isPublic(): bool { return $this->visibility === self::PUBLIC; }
    public function isOfficial(): bool { return $this->official; }
    public function setOfficial(bool $official): void { $this->official = $official; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
    public function getDateUpdate(): DateTimeInterface { return $this->dateUpdate; }
    public function setDateUpdate(DateTimeInterface $dateUpdate): void { $this->dateUpdate = $dateUpdate; }
    public function getDatePublic(): ?DateTimeInterface { return $this->datePublic; }
    public function setDatePublic(?DateTimeInterface $datePublic): void { $this->datePublic = $datePublic; }

    /** @return Collection<int, LibraryEntry> */
    public function getEntries(): Collection { return $this->entries; }

    public function addEntry(LibraryEntry $entry): void
    {
        $entry->setLibrary($this);
        $this->entries->add($entry);
    }

    public function removeEntry(LibraryEntry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    public function hasDecklist(PublishedDeck $decklist): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getDecklist()->getId() === $decklist->getId()) {
                return true;
            }
        }
        return false;
    }

    /** The first of its decks readers see, for its thumbnail when it has none of its own */
    public function getFirstAvailableDecklist(): ?PublishedDeck
    {
        foreach ($this->entries as $entry) {
            if ($entry->isAvailable()) {
                return $entry->getDecklist();
            }
        }
        return null;
    }

    /** @return string[] */
    public function getTagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(' ', (string) $this->tags)), 'strlen'));
    }
}
