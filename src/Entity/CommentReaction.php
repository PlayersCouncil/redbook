<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's Cheers on a comment (Ketura, 2026-11-11), and how far up they took it: level 1 a
 * Cheers, 2 on fire, 3 crowned, as on a decklist (App\Services\Reactions)
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment_reaction')]
#[ORM\UniqueConstraint(name: 'comment_reaction_user', columns: ['comment_id', 'user_id'])]
#[ORM\Index(name: 'comment_reaction_user_idx', columns: ['user_id'])]
class CommentReaction
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

    #[ORM\Column(name: 'level', type: 'smallint', options: ['default' => 1])]
    protected int $level = 1;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    public function __construct(Comment $comment, User $user)
    {
        $this->comment = $comment;
        $this->user = $user;
        $this->dateCreation = new DateTime();
    }

    public function getComment(): Comment { return $this->comment; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): void { $this->user = $user; }
    public function getLevel(): int { return $this->level; }
}
