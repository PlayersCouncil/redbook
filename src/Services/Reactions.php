<?php

namespace App\Services;

use App\Entity\Comment;
use App\Entity\Library;
use App\Entity\PublishedDeck;
use App\Entity\Reactable;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Cheers, fire and crown (Ketura, 2026-11-11), one ladder for decklists, libraries and comments.
 * Each user has a level on each: none, a Cheers (1), on fire (2), crowned (3), one step at a
 * time up, and down to any level below. Like reddit, everything starts with its author's own
 * Cheers, which they may take back; they can't fire or crown their own.
 * A level's points (Reactable::POINTS) count toward the score in the popularity sorts, and,
 * on a decklist or a comment, the author's reputation; never for their own Cheers.
 * One row per user and subject keeps the level; the subject keeps the counts.
 */
class Reactions
{
    /**
     * Where each kind keeps its levels and counts. reputation: whether a level counts toward
     * the author's; touch: a column set to now when it changes; dated: the row's own date
     */
    private const TABLES = [
        PublishedDeck::class => ['rows' => 'vote', 'key' => 'published_deck_id', 'subject' => 'published_deck',
            'counts' => ['nb_votes', 'nb_fires', 'nb_crowns'], 'reputation' => true, 'touch' => 'date_update', 'dated' => false],
        Library::class => ['rows' => 'library_vote', 'key' => 'library_id', 'subject' => 'library',
            'counts' => ['nb_votes', 'nb_fires', 'nb_crowns'], 'reputation' => false, 'touch' => null, 'dated' => false],
        Comment::class => ['rows' => 'comment_reaction', 'key' => 'comment_id', 'subject' => 'comment',
            'counts' => ['nb_cheers', 'nb_fires', 'nb_crowns'], 'reputation' => true, 'touch' => null, 'dated' => true],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** All a level's points: a Cheers 1, on fire 3, crowned 6 */
    public static function points(int $level): int
    {
        $sum = 0;
        foreach (Reactable::POINTS as $step => $points) {
            $sum += $step <= $level ? $points : 0;
        }
        return $sum;
    }

    /** How far up the user has taken it */
    public function level(Reactable $subject, ?User $user): int
    {
        if ($user === null || $subject->getId() === null) {
            return Reactable::NONE;
        }
        $t = $this->tables($subject);
        return (int) $this->db->fetchOne("SELECT level FROM {$t['rows']} WHERE {$t['key']} = ? AND user_id = ?", [$subject->getId(), $user->getId()]);
    }

    /**
     * The user's levels on several of a kind, by id (none left out)
     * @param class-string<Reactable> $class
     * @param int[] $ids
     * @return array<int, int>
     */
    public function levels(string $class, array $ids, User $user): array
    {
        $t = self::TABLES[$class];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->fetchAllKeyValue(
            "SELECT {$t['key']}, level FROM {$t['rows']} WHERE user_id = ? AND {$t['key']} IN (?)",
            [$user->getId(), $ids],
            [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER]
        );
        return array_map('intval', $rows);
    }

    /**
     * Takes the user's level to $level: one step up from where it is, or down to any below.
     * @return array{level: int, cheers: int, fires: int, crowns: int}
     */
    public function set(Reactable $subject, User $user, int $level): array
    {
        if ($level < Reactable::NONE || $level > Reactable::CROWN) {
            throw new BadRequestHttpException('No such level.');
        }
        $author = $subject->getUser();
        $own = $author !== null && $author->getId() === $user->getId();
        if ($own && $level > Reactable::CHEERS) {
            throw new AccessDeniedHttpException("You can't set your own on fire or crown it.");
        }
        $t = $this->tables($subject);
        $id = $subject->getId();
        $this->db->transactional(function () use ($t, $id, $user, $author, $own, $level) {
            $current = (int) $this->db->fetchOne("SELECT level FROM {$t['rows']} WHERE {$t['key']} = ? AND user_id = ? FOR UPDATE", [$id, $user->getId()]);
            if ($level > $current + 1) {
                throw new BadRequestHttpException($current === Reactable::NONE ? 'Cheers it first.' : 'Set it on fire first.');
            }
            if ($level === $current) {
                return;
            }
            if ($level === Reactable::NONE) {
                $this->db->executeStatement("DELETE FROM {$t['rows']} WHERE {$t['key']} = ? AND user_id = ?", [$id, $user->getId()]);
            } elseif ($current === Reactable::NONE) {
                $this->insert($t, $id, $user->getId(), $level);
            } else {
                $this->db->executeStatement("UPDATE {$t['rows']} SET level = ? WHERE {$t['key']} = ? AND user_id = ?", [$level, $id, $user->getId()]);
            }
            $this->count($t, $id, $current, $level);
            if ($t['reputation'] && !$own && $author !== null) {
                $this->db->executeStatement('UPDATE `user` SET reputation = reputation + ? WHERE id = ?', [self::points($level) - self::points($current), $author->getId()]);
            }
        });
        return ['level' => $level] + $this->reload($subject);
    }

    /**
     * Its author's own Cheers, for something just made (SelfCheersListener); a no-op if they
     * already have one
     */
    public function seed(Reactable $subject): void
    {
        $author = $subject->getUser();
        if ($author === null || $subject->getId() === null) {
            return;
        }
        $t = $this->tables($subject);
        if ($this->insert($t, $subject->getId(), $author->getId(), Reactable::CHEERS, true)) {
            $this->count($t, $subject->getId(), Reactable::NONE, Reactable::CHEERS);
            [$cheers, $fires, $crowns] = $subject->getReactionCounts();
            $subject->setReactionCounts($cheers + 1, $fires, $crowns);
        }
    }

    /** @return array{rows: string, key: string, subject: string, counts: string[], reputation: bool, touch: ?string, dated: bool} */
    private function tables(Reactable $subject): array
    {
        foreach (self::TABLES as $class => $t) {
            if ($subject instanceof $class) {
                return $t;
            }
        }
        throw new \LogicException(get_class($subject) . ' has no reactions table.');
    }

    /** One row; true if it went in (with $ignore, false when there was one already) */
    private function insert(array $t, int $id, int $userId, int $level, bool $ignore = false): bool
    {
        $columns = "{$t['key']}, user_id, level" . ($t['dated'] ? ', date_creation' : '');
        $values = '?, ?, ?' . ($t['dated'] ? ', NOW()' : '');
        return $this->db->executeStatement('INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO {$t['rows']} ($columns) VALUES ($values)", [$id, $userId, $level]) > 0;
    }

    /** The subject's counts, from one level to another */
    private function count(array $t, int $id, int $from, int $to): void
    {
        $sets = [];
        foreach ($t['counts'] as $i => $column) {
            $step = $i + 1;
            $delta = ($to >= $step ? 1 : 0) - ($from >= $step ? 1 : 0);
            if ($delta !== 0) {
                $sets[] = "$column = GREATEST(0, $column + ($delta))";
            }
        }
        if ($t['touch']) {
            $sets[] = "{$t['touch']} = NOW()";
        }
        if ($sets) {
            $this->db->executeStatement("UPDATE {$t['subject']} SET " . implode(', ', $sets) . ' WHERE id = ?', [$id]);
        }
    }

    /** The counts as they now stand, into the entity too */
    private function reload(Reactable $subject): array
    {
        $t = $this->tables($subject);
        $row = $this->db->fetchNumeric('SELECT ' . implode(', ', $t['counts']) . " FROM {$t['subject']} WHERE id = ?", [$subject->getId()]) ?: [0, 0, 0];
        [$cheers, $fires, $crowns] = array_map('intval', $row);
        $subject->setReactionCounts($cheers, $fires, $crowns);
        return ['cheers' => $cheers, 'fires' => $fires, 'crowns' => $crowns];
    }
}
