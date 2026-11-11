<?php

namespace App\Services;

use App\Entity\Comment;
use App\Entity\ModerationAction;
use App\Entity\PublishedDeck;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Deleting what someone else made needs a reason (Ketura, 2026-11-11), kept with who did it
 * (ModerationAction). Shared by everything that can be deleted by someone other than its
 * owner: comments (moderators), decklists (admins).
 */
class ModerationLog
{
    public const MAX_REASON = 2000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Whether deleting it needs a reason: it isn't the actor's own */
    public static function needsReason(User $actor, ?User $owner): bool
    {
        return $owner === null || $owner->getId() !== $actor->getId();
    }

    /** The reason as given, refused if it's missing or too long */
    public static function cleanReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new BadRequestHttpException('Say why it is being deleted.');
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new BadRequestHttpException('The reason is too long (at most ' . self::MAX_REASON . ' characters).');
        }
        return $reason;
    }

    /** Kept with the next flush */
    public function deletedComment(User $actor, Comment $comment, string $reason): void
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($comment->getTextMd() ?? strip_tags((string) $comment->getText()))));
        $this->em->persist(new ModerationAction($actor, ModerationAction::COMMENT_DELETE, 'comment', (int) $comment->getId(), $comment->getUser(), $text, self::cleanReason($reason)));
    }

    public function deletedDecklist(User $actor, PublishedDeck $decklist, string $reason): void
    {
        $this->em->persist(new ModerationAction($actor, ModerationAction::DECKLIST_DELETE, 'decklist', (int) $decklist->getId(), $decklist->getUser(), (string) $decklist->getName(), self::cleanReason($reason)));
    }
}
