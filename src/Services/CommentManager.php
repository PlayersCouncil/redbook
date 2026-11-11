<?php

namespace App\Services;

use App\Classes\CommentTree;
use App\Entity\Comment;
use App\Entity\CommentReaction;
use App\Entity\CommentReport;
use App\Entity\CommentRevision;
use App\Entity\Library;
use App\Entity\PublishedDeck;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Comments on decklists and libraries (Ketura, 2026-11-11), whatever they're on: posting and
 * replying, editing (keeping each version), deleting, hiding, Cheers and fire, reports, and
 * the threads as the pages draw them.
 *
 * Who may do what:
 * - anyone logged in who can see the subject: post, reply, Cheers (not their own), report
 * - the author: edit and delete their own
 * - a moderator (ROLE_MODERATOR; admins are): edit, delete and hide anyone's
 * - whoever owns the decklist or library: hide any on it (folded away, a click opens it)
 */
class CommentManager
{
    public const KINDS = ['decklist', 'library'];
    public const TOKEN = 'comment';
    /** Longest comment and report, in characters */
    public const MAX_LENGTH = 20000;
    public const MAX_REPORT = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly Texts $texts,
        private readonly LibraryManager $libraries,
        private readonly UrlGeneratorInterface $router,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly ModerationLog $moderation,
        private readonly string $emailSenderAddress,
    ) {
    }

    /* ---- what comments are on ---- */

    /** The decklist (by id) or library (by uuid) the viewer can see and comment on */
    public function subject(string $kind, string $id, ?User $viewer): PublishedDeck|Library
    {
        if ($kind === 'decklist') {
            $decklist = $this->em->getRepository(PublishedDeck::class)->find((int) $id);
            // a withdrawn decklist takes no comments (Ketura, 2026-11-07)
            if ($decklist && !$decklist->isWithdrawn()) {
                return $decklist;
            }
        } elseif ($kind === 'library') {
            $library = $this->em->getRepository(Library::class)->findOneBy(['uuid' => $id]);
            if ($library && $this->libraries->canView($viewer, $library)) {
                return $library;
            }
        }
        throw new NotFoundHttpException("That isn't there.");
    }

    public static function kindOf(PublishedDeck|Library $subject): string
    {
        return $subject instanceof Library ? 'library' : 'decklist';
    }

    /** The id the comment forms post: a decklist's number, a library's uuid */
    public static function idOf(PublishedDeck|Library $subject): string
    {
        return $subject instanceof Library ? (string) $subject->getUuid() : (string) $subject->getId();
    }

    /** The subject's page, where its comments are */
    public function url(PublishedDeck|Library $subject, int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        if ($subject instanceof Library) {
            return $subject->getPublicId() !== null && $subject->isPublic()
                ? $this->router->generate('library_view', ['public_id' => $subject->getPublicId(), 'library_name' => $subject->getNameCanonical()], $type)
                : $this->router->generate('library_view_private', ['library_uuid' => $subject->getUuid()], $type);
        }
        return $this->router->generate('decklist_detail', ['decklist_id' => $subject->getId(), 'decklist_name' => $subject->getNameCanonical()], $type);
    }

    /** A comment's own address: its subject's page, scrolled to it */
    public function commentUrl(Comment $comment, int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->url($comment->getSubject(), $type) . '#comment-' . $comment->getId();
    }

    /** The subject's comments as threads (CommentTree) */
    public function threads(PublishedDeck|Library $subject): array
    {
        return CommentTree::build($subject->getComments()->toArray());
    }

    /** The comments standing: not deleted */
    public function countStanding(PublishedDeck|Library $subject): int
    {
        return $subject->getComments()->filter(fn (Comment $c) => !$c->isDeleted())->count();
    }

    /* ---- who may ---- */

    public function isModerator(): bool
    {
        return $this->security->isGranted('ROLE_MODERATOR');
    }

    /** Delete it: the author, or a moderator */
    public function canDelete(?User $user, Comment $comment): bool
    {
        return $user !== null && !$comment->isDeleted() && ($comment->getUser() === $user || $this->isModerator());
    }

    /** Edit it: as for deleting, and only one whose markdown was kept */
    public function canEdit(?User $user, Comment $comment): bool
    {
        return $this->canDelete($user, $comment) && $comment->getTextMd() !== null;
    }

    public function canHide(?User $user, Comment $comment): bool
    {
        return $user !== null && !$comment->isDeleted() && ($comment->getSubject()?->getUser() === $user || $this->isModerator());
    }

    /** A comment the user can act on: its subject still there, and visible to them */
    public function find(int $id, ?User $viewer): Comment
    {
        $comment = $this->em->getRepository(Comment::class)->find($id);
        $subject = $comment?->getSubject();
        if (!$comment || !$subject || ($subject instanceof PublishedDeck && $subject->isWithdrawn())
            || ($subject instanceof Library && !$this->libraries->canView($viewer, $subject))) {
            throw new NotFoundHttpException("That comment isn't there.");
        }
        return $comment;
    }

    /* ---- writing ---- */

    /** A comment, or with $parent a reply to it; mails whoever asked to hear of it */
    public function post(PublishedDeck|Library $subject, User $user, string $markdown, ?int $parentId): Comment
    {
        $markdown = $this->clean($markdown);
        $parent = null;
        if ($parentId) {
            $parent = $this->em->getRepository(Comment::class)->find($parentId);
            if (!$parent || $parent->getSubject() !== $subject || $parent->isDeleted()) {
                throw new BadRequestHttpException("The comment you're replying to isn't there any more.");
            }
        }
        $comment = new Comment();
        $comment->setUser($user);
        $comment->setTextMd($markdown);
        $comment->setText($this->texts->comment($markdown));
        $comment->setParent($parent);
        if ($subject instanceof Library) {
            $subject->addComment($comment);
        } else {
            $comment->setDecklist($subject);
            $subject->addComment($comment);
            $subject->setDateUpdate(new DateTime());
        }
        $this->em->persist($comment);
        $this->em->persist(new CommentRevision($comment, $user, $markdown, $comment->getText(), $comment->getDateCreation()));
        $subject->setNbComments($this->countStanding($subject));
        $this->em->flush();
        $this->notify($comment);
        return $comment;
    }

    /** New text, the old kept in its history */
    public function edit(Comment $comment, User $user, string $markdown): void
    {
        if (!$this->canDelete($user, $comment)) {
            throw new AccessDeniedHttpException("You can't edit that comment.");
        }
        if ($comment->getTextMd() === null) {
            throw new BadRequestHttpException('This comment is from before comments could be edited. Delete it and post it again instead.');
        }
        $markdown = $this->clean($markdown);
        if ($markdown === $this->markdownOf($comment)) {
            return;
        }
        $this->keepOriginal($comment);
        $comment->setTextMd($markdown);
        $comment->setText($this->texts->comment($markdown));
        $comment->setDateEdited(new DateTime());
        $this->em->persist(new CommentRevision($comment, $user, $markdown, $comment->getText()));
        $this->em->flush();
    }

    /**
     * Deleted: by its author, or a moderator, who must say why (kept in the moderation log;
     * Ketura, 2026-11-11). Replies to it stay where they are.
     */
    public function delete(Comment $comment, User $user, ?string $reason = null): void
    {
        if (!$this->canDelete($user, $comment)) {
            throw new AccessDeniedHttpException("You can't delete that comment.");
        }
        if (ModerationLog::needsReason($user, $comment->getUser())) {
            $this->moderation->deletedComment($user, $comment, (string) $reason);
        }
        $this->keepOriginal($comment);
        $comment->delete($user);
        $subject = $comment->getSubject();
        $subject->setNbComments($this->countStanding($subject));
        $this->em->flush();
    }

    /** Folded away for everyone (anyone can open it), or shown again */
    public function hide(Comment $comment, User $user, bool $hidden): void
    {
        if (!$this->canHide($user, $comment)) {
            throw new AccessDeniedHttpException("You can't hide that comment.");
        }
        $comment->setIsHidden($hidden);
        $this->em->flush();
    }

    /**
     * Cheers, or takes it back (and the fire with it). Not on your own. As a decklist's: one
     * to the author's reputation, and a fire PublishedDeck::FIRE_WEIGHT more.
     * @return array{cheers: int, fires: int, cheered: bool, fired: bool}
     */
    public function cheer(Comment $comment, User $user): array
    {
        $this->reactable($comment, $user);
        $reaction = $this->reaction($comment, $user);
        $author = $comment->getUser();
        if ($reaction) {
            if ($reaction->isFire()) {
                $comment->setNbFires($comment->getNbFires() - 1);
                $author->setReputation($author->getReputation() - PublishedDeck::FIRE_WEIGHT);
            }
            $comment->setNbCheers($comment->getNbCheers() - 1);
            $author->setReputation($author->getReputation() - 1);
            $this->em->remove($reaction);
            $reaction = null;
        } else {
            $reaction = new CommentReaction($comment, $user);
            $this->em->persist($reaction);
            $comment->setNbCheers($comment->getNbCheers() + 1);
            $author->setReputation($author->getReputation() + 1);
        }
        $this->em->flush();
        return $this->reactions($comment, $reaction);
    }

    /** Sets it on fire, or puts the fire out; only over a Cheers */
    public function fire(Comment $comment, User $user): array
    {
        $this->reactable($comment, $user);
        $reaction = $this->reaction($comment, $user) ?? throw new BadRequestHttpException('Cheers it first.');
        $on = !$reaction->isFire();
        $reaction->setFire($on);
        $comment->setNbFires($comment->getNbFires() + ($on ? 1 : -1));
        $author = $comment->getUser();
        $author->setReputation($author->getReputation() + ($on ? 1 : -1) * PublishedDeck::FIRE_WEIGHT);
        $this->em->flush();
        return $this->reactions($comment, $reaction);
    }

    /** To the moderators: a new report, or a new reason on the user's open one */
    public function report(Comment $comment, User $user, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REPORT) {
            throw new BadRequestHttpException('Say what is wrong with it, in at most ' . self::MAX_REPORT . ' characters.');
        }
        $report = $this->em->getRepository(CommentReport::class)->findOneBy(['comment' => $comment, 'user' => $user]);
        if ($report) {
            $report->setReason($reason);
        } else {
            $this->em->persist(new CommentReport($comment, $user, $reason));
        }
        $this->em->flush();
    }

    /* ---- reading ---- */

    /**
     * What it says, as written: the markdown, kept since 2026-11-11 (text_md; the html in text
     * is only its render). Null for a comment from before, which has only the render: nothing
     * is made back from html (Ketura, 2026-11-11), so those can't be edited.
     */
    public function markdownOf(Comment $comment): ?string
    {
        return $comment->getTextMd();
    }

    /**
     * Its versions, newest first; one from before these were kept is its text as it stands.
     * @return array<array{date: \DateTimeInterface, user: ?User, html: string, edited_by_other: bool}>
     */
    public function history(Comment $comment): array
    {
        $revisions = $this->em->getRepository(CommentRevision::class)->findBy(['comment' => $comment], ['id' => 'DESC']);
        $list = array_map(fn (CommentRevision $r) => [
            'date' => $r->getDateCreation(),
            'user' => $r->getUser(),
            'html' => $r->getText(),
            'edited_by_other' => $r->getUser() !== null && $r->getUser() !== $comment->getUser(),
        ], $revisions);
        return $list ?: [['date' => $comment->getDateCreation(), 'user' => $comment->getUser(), 'html' => (string) $comment->getText(), 'edited_by_other' => false]];
    }

    /**
     * What the viewer has done to and may do with the subject's comments, for the page's script
     * @return array{user: int, moderator: bool, owner: bool, cheered: int[], fired: int[], reported: int[]}
     */
    public function state(PublishedDeck|Library $subject, User $user): array
    {
        $ids = array_map(fn (Comment $c) => $c->getId(), $subject->getComments()->toArray());
        $cheered = $fired = $reported = [];
        if ($ids) {
            $rows = $this->em->createQuery('SELECT IDENTITY(r.comment) AS id, r.fire FROM ' . CommentReaction::class . ' r WHERE r.user = :user AND r.comment IN (:ids)')
                ->setParameters(['user' => $user, 'ids' => $ids])->getArrayResult();
            foreach ($rows as $row) {
                $cheered[] = (int) $row['id'];
                if ($row['fire']) {
                    $fired[] = (int) $row['id'];
                }
            }
            $reported = array_map('intval', array_column($this->em->createQuery('SELECT IDENTITY(r.comment) AS id FROM ' . CommentReport::class . ' r WHERE r.user = :user AND r.comment IN (:ids) AND r.dateResolved IS NULL')
                ->setParameters(['user' => $user, 'ids' => $ids])->getArrayResult(), 'id'));
        }
        return [
            'user' => $user->getId(),
            'moderator' => $this->isModerator(),
            'owner' => $subject->getUser() === $user,
            'cheered' => $cheered,
            'fired' => $fired,
            'reported' => $reported,
        ];
    }

    /* ---- helpers ---- */

    private function clean(string $markdown): string
    {
        $markdown = trim(str_replace("\r\n", "\n", $markdown));
        if ($markdown === '') {
            throw new BadRequestHttpException('The comment is empty.');
        }
        if (mb_strlen($markdown) > self::MAX_LENGTH) {
            throw new BadRequestHttpException('That comment is too long (at most ' . self::MAX_LENGTH . ' characters).');
        }
        return $markdown;
    }

    /** A comment from before revisions were kept: its text as posted, as its first */
    private function keepOriginal(Comment $comment): void
    {
        if (!$this->em->getRepository(CommentRevision::class)->count(['comment' => $comment])) {
            $this->em->persist(new CommentRevision($comment, $comment->getUser(), $comment->getTextMd(), (string) $comment->getText(), $comment->getDateCreation()));
        }
    }

    private function reactable(Comment $comment, User $user): void
    {
        if ($comment->isDeleted()) {
            throw new BadRequestHttpException('That comment was deleted.');
        }
        if ($comment->getUser() === $user) {
            throw new AccessDeniedHttpException("You can't cheer your own comment.");
        }
    }

    private function reaction(Comment $comment, User $user): ?CommentReaction
    {
        return $this->em->getRepository(CommentReaction::class)->findOneBy(['comment' => $comment, 'user' => $user]);
    }

    private function reactions(Comment $comment, ?CommentReaction $mine): array
    {
        return ['cheers' => $comment->getNbCheers(), 'fires' => $comment->getNbFires(), 'cheered' => $mine !== null, 'fired' => $mine?->isFire() ?? false];
    }

    /**
     * Mails about a new comment: the subject's owner (notif_author), everyone else who
     * commented on it (notif_commenter), whoever it mentions (`@name`) or replies to
     * (notif_mention); one mail each, the most direct reason first; never the writer
     */
    private function notify(Comment $comment): void
    {
        $subject = $comment->getSubject();
        $writer = $comment->getUser();
        $spool = [];
        $add = function (?User $to, string $why) use (&$spool, $writer) {
            if ($to && $to !== $writer && $to->getEmail() && !$to->isManaged() && !isset($spool[$to->getEmail()])) {
                $spool[$to->getEmail()] = $why;
            }
        };
        if ($comment->getParent() && $comment->getParent()->getUser()?->getIsNotifMention()) {
            $add($comment->getParent()->getUser(), 'reply');
        }
        if (preg_match_all('/`@([\w_]+)`/', (string) $comment->getTextMd(), $matches)) {
            foreach (array_unique($matches[1]) as $name) {
                $mentioned = $this->em->getRepository(User::class)->findOneBy(['username' => $name]);
                if ($mentioned && $mentioned->getIsNotifMention()) {
                    $add($mentioned, 'mention');
                }
            }
        }
        if ($subject->getUser()->getIsNotifAuthor()) {
            $add($subject->getUser(), 'author');
        }
        foreach ($subject->getComments() as $other) {
            if (!$other->isDeleted() && $other->getUser()?->getIsNotifCommenter()) {
                $add($other->getUser(), 'commenter');
            }
        }
        $data = [
            'username' => $writer->getDisplayName(),
            'kind' => self::kindOf($subject),
            'subject_name' => $subject->getName(),
            'url' => $this->commentUrl($comment, UrlGeneratorInterface::ABSOLUTE_URL),
            'comment' => $comment->getText(),
            'profile' => $this->router->generate('user_profile_edit', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        foreach ($spool as $email => $why) {
            try {
                $this->mailer->send((new Email())
                    ->subject('[Red Book] ' . match ($why) {
                        'reply' => $writer->getDisplayName() . ' replied to your comment',
                        'mention' => $writer->getDisplayName() . ' mentioned you',
                        default => 'New comment on ' . $subject->getName(),
                    })
                    ->from(new Address($this->emailSenderAddress, $writer->getDisplayName()))
                    ->to($email)
                    ->html($this->twig->render('Emails/newcomment.html.twig', $data + ['why' => $why])));
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Comment notification not sent: {message}', ['message' => $e->getMessage()]);
            }
        }
    }
}
