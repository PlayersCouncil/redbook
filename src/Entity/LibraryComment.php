<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment on a library (Ketura, 2026-11-09): as a decklist's (Comment), its text already
 * rendered from markdown; the library's owner may hide one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'library_comment')]
#[ORM\Index(name: 'library_comment_library_idx', columns: ['library_id', 'date_creation'])]
class LibraryComment
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Library::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Library $library;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    #[ORM\Column(name: 'text', type: 'text')]
    protected string $text = '';

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    #[ORM\Column(name: 'is_hidden', type: 'boolean', options: ['default' => false])]
    protected bool $isHidden = false;

    public function __construct(User $user, string $html)
    {
        $this->user = $user;
        $this->text = $html;
        $this->dateCreation = new DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getLibrary(): Library { return $this->library; }
    public function setLibrary(Library $library): void { $this->library = $library; }
    public function getUser(): User { return $this->user; }
    public function getText(): string { return $this->text; }
    public function getDateCreation(): DateTimeInterface { return $this->dateCreation; }
    public function getIsHidden(): bool { return $this->isHidden; }
    public function setIsHidden(bool $hidden): void { $this->isHidden = $hidden; }
}
