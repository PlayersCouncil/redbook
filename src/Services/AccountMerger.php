<?php

namespace App\Services;

use App\Entity\Comment;
use App\Entity\Deck;
use App\Entity\HistoricClaim;
use App\Entity\PublishedDeck;
use App\Entity\Review;
use App\Entity\Reviewcomment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Folds one account into another (Ketura, 2026-11-02). Used when a claim on a
 * historic account is approved, and later when historians merge two historic
 * accounts that turn out to be the same person.
 *
 * Everything the account owns moves: private decks, published decks (which stay
 * published), comments, reviews, favourites and votes. The merged account's name
 * becomes an alias on the surviving account, and the merged account stays behind
 * as a pointer (merged_into) so the archive import and old links still find the
 * person.
 */
class AccountMerger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param User|null $decidedBy who approved it, for the claims it settles */
    public function merge(User $from, User $into, ?User $decidedBy = null): void
    {
        if ($from->getId() === $into->getId()) {
            throw new InvalidArgumentException('An account can\'t be merged into itself');
        }
        if ($from->getMergedInto() !== null) {
            throw new InvalidArgumentException(sprintf('%s was already merged into another account', $from->getUsername()));
        }
        if (!$from->isHistoric()) {
            // a person's own login is never merged away; only historic accounts are (for now)
            throw new InvalidArgumentException(sprintf('%s isn\'t a historic account', $from->getUsername()));
        }

        foreach ([Deck::class, PublishedDeck::class, Comment::class, Review::class, Reviewcomment::class] as $class) {
            foreach ($this->em->getRepository($class)->findBy(['user' => $from]) as $owned) {
                $owned->setUser($into);
            }
        }

        // join tables: keep the surviving account's own row where both have one
        $connection = $this->em->getConnection();
        foreach (['favorite' => 'published_deck_id', 'vote' => 'published_deck_id', 'reviewvote' => 'review_id'] as $table => $column) {
            $connection->executeStatement(
                "INSERT IGNORE INTO $table ($column, user_id) SELECT $column, ? FROM $table WHERE user_id = ?",
                [$into->getId(), $from->getId()]
            );
            $connection->executeStatement("DELETE FROM $table WHERE user_id = ?", [$from->getId()]);
        }

        $into->addAlias($from->getDisplayName(), $from->getHistoricSource());
        foreach ($from->getAliases() as $alias) {
            $into->addAlias($alias['name'], $alias['source'] ?? null);
        }
        $into->setReputation($into->getReputation() + max(0, $from->getReputation() - 1));
        $from->setMergedInto($into);

        // anything still asking for the merged account is settled by the merge
        foreach ($this->em->getRepository(HistoricClaim::class)->findBy(['historicUser' => $from, 'status' => HistoricClaim::STATUS_PENDING]) as $claim) {
            if ($claim->getUser()->getId() !== $into->getId()) {
                $claim->decide(HistoricClaim::STATUS_REJECTED, $decidedBy ?? $into);
            }
        }
    }

    /** The account a historic account ended up in, following merges */
    public static function resolve(User $user): User
    {
        $seen = [];
        while ($user->getMergedInto() !== null && !isset($seen[$user->getId()])) {
            $seen[$user->getId()] = true;
            $user = $user->getMergedInto();
        }
        return $user;
    }
}
