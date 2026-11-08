<?php

namespace App\Repository;

use App\Entity\DecklistLine;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckInterface;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

class PublishedDeckRepository extends EntityRepository
{
    /** The first public decklist with the same cards */
    public function findDuplicate(PublishedDeckInterface $decklist)
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d, f')
            ->join('d.faction', 'f')
            ->andWhere('d.signature = ?1')
            ->andWhere('d.withdrawn = false');

        $qb->setParameter(1, $decklist->getSignature());
        $qb->orderBy('d.dateCreation', 'ASC');
        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * The versions in the decklist's line, newest first (Ketura, 2026-11-07): the public
     * ones, and the decklist itself whatever its state
     * @return PublishedDeck[]
     */
    public function findVersions(PublishedDeck $decklist): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.line = :line')
            ->andWhere('d.withdrawn = false OR d = :self')
            ->setParameter('line', $decklist->getLine())
            ->setParameter('self', $decklist)
            ->orderBy('d.version', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The tags on public decklists, with how many carry each: [tag => count] (Ketura,
     * 2026-11-07). Archive decks' included; private decks' tags stay private.
     */
    public function countPublicTags(): array
    {
        $counts = [];
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT p.tags FROM published_deck p WHERE p.tags IS NOT NULL AND p.tags <> '' AND p.withdrawn = 0"
        );
        foreach ($rows as $tags) {
            foreach (array_unique(preg_split('/\s+/', trim((string) $tags), -1, PREG_SPLIT_NO_EMPTY)) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /** The number the next version in the line gets; withdrawn versions keep theirs */
    public function nextVersion(DecklistLine $line): int
    {
        if ($line->getId() === null) {
            return 1;
        }
        return 1 + (int) $this->createQueryBuilder('d')
            ->select('MAX(d.version)')
            ->andWhere('d.line = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The latest version of each of the user's lines, newest first: what a new
     * publication can continue
     * @return PublishedDeck[]
     */
    public function findLatestOfLines(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.version = (SELECT MAX(d2.version) FROM ' . PublishedDeck::class . ' d2 WHERE d2.line = d.line)')
            ->setParameter('user', $user)
            ->orderBy('d.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The newest public version of the line, if it's newer than this one */
    public function findNewerVersion(PublishedDeck $decklist): ?PublishedDeck
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.line = :line')
            ->andWhere('d.version > :version')
            ->andWhere('d.withdrawn = false')
            ->setParameter('line', $decklist->getLine())
            ->setParameter('version', $decklist->getVersion())
            ->orderBy('d.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Public decklists by author and name, for picking what a deck was derived from
     * (Ketura, 2026-11-07); either part can be blank
     * @return PublishedDeck[]
     */
    public function lookup(string $author, string $name, ?int $except, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d, u')
            ->join('d.user', 'u')
            ->andWhere('d.withdrawn = false')
            ->orderBy('d.dateCreation', 'DESC')
            ->setMaxResults($limit);
        if ($author !== '') {
            // historic accounts are known by their display names
            $qb->andWhere('u.username LIKE :author OR u.displayName LIKE :author')->setParameter('author', '%' . addcslashes($author, '%_') . '%');
        }
        if ($name !== '') {
            $qb->andWhere('d.name LIKE :name')->setParameter('name', '%' . addcslashes($name, '%_') . '%');
        }
        if ($except !== null) {
            $qb->andWhere('d.id != :except')->setParameter('except', $except);
        }
        return $qb->getQuery()->getResult();
    }
}
