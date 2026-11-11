<?php

namespace App\Repository;

use App\Entity\Library;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/** Libraries (Ketura, 2026-11-08): the user's, the public ones, and the picker's */
class LibraryRepository extends EntityRepository
{
    /**
     * A page of libraries matching the filters (LibraryManager::getFilters): the owner's,
     * or with no owner the public ones
     * @return array{0: Library[], 1: int} the page, and how many match
     */
    public function browse(array $filters, ?User $owner, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('l')->join('l.user', 'u');
        if ($owner !== null) {
            $qb->andWhere('l.user = :owner')->setParameter('owner', $owner);
            if (!empty($filters['show'])) {
                $qb->andWhere('l.visibility IN (:shows)')->setParameter('shows', $filters['show']);
            }
        } else {
            $qb->andWhere('l.visibility = :public')->setParameter('public', Library::PUBLIC);
        }
        $this->applyFilters($qb, $filters);

        $total = (int) (clone $qb)->select('COUNT(l.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        match ($filters['sort'] ?? 'new') {
            'old' => $qb->orderBy('l.dateUpdate', 'ASC'),
            'name' => $qb->orderBy('l.name', 'ASC'),
            'popular' => $qb->addSelect(Library::SCORE_DQL . ' AS HIDDEN score')->orderBy('score', 'DESC')->addOrderBy('l.nbFavorites', 'DESC'),
            'size' => $qb->addSelect('(SELECT COUNT(e2.id) FROM App\Entity\LibraryEntry e2 WHERE e2.library = l) AS HIDDEN size')->orderBy('size', 'DESC'),
            default => $qb->orderBy('l.dateUpdate', 'DESC'),
        };
        $qb->addOrderBy('l.id', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $libraries = $qb->getQuery()->getResult();
        if ($libraries) {
            // their decks, for the counts and the thumbnails
            $this->getEntityManager()->createQuery('SELECT l, e, d, tc FROM ' . Library::class . ' l LEFT JOIN l.entries e LEFT JOIN e.decklist d LEFT JOIN d.thumbnailCard tc WHERE l IN (:libraries)')
                ->setParameter('libraries', $libraries)
                ->getResult();
        }
        return [$libraries, $total];
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (($filters['name'] ?? '') !== '') {
            $qb->andWhere('l.name LIKE :name')->setParameter('name', '%' . addcslashes($filters['name'], '%_') . '%');
        }
        if (($filters['author'] ?? '') !== '') {
            $qb->andWhere('u.username LIKE :author')->setParameter('author', '%' . addcslashes($filters['author'], '%_') . '%');
        }
        foreach (array_values($filters['tags'] ?? []) as $i => $tag) {
            $qb->andWhere("CONCAT(' ', COALESCE(l.tags, ''), ' ') LIKE :tag$i")->setParameter("tag$i", '% ' . addcslashes($tag, '%_') . ' %');
        }
        // a tab's own tag, not one of the search's (Ketura, 2026-11-09)
        if (($filters['tab_tag'] ?? '') !== '') {
            $qb->andWhere("CONCAT(' ', COALESCE(l.tags, ''), ' ') LIKE :tabtag")->setParameter('tabtag', '% ' . addcslashes($filters['tab_tag'], '%_') . ' %');
        }
        foreach (array_values($filters['xtags'] ?? []) as $i => $tag) {
            $qb->andWhere("CONCAT(' ', COALESCE(l.tags, ''), ' ') NOT LIKE :xtag$i")->setParameter("xtag$i", '% ' . addcslashes($tag, '%_') . ' %');
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('l.dateUpdate >= :from')->setParameter('from', new \DateTime($filters['from']));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('l.dateUpdate < :to')->setParameter('to', (new \DateTime($filters['to']))->modify('+1 day'));
        }
        // Libraries are the PC's, official; User Libraries everyone else's (Ketura, 2026-11-09)
        if (($filters['kind'] ?? null) === 'official') {
            $qb->andWhere('l.official = true');
        } elseif (($filters['kind'] ?? null) === 'user') {
            $qb->andWhere('l.official = false');
        } elseif (($filters['kind'] ?? null) === 'rest') {
            // User Libraries: everyone else's, and an official library on no tab of its own
            // (Ketura, 2026-11-09: else it can't be found to be tagged)
            $untagged = ['l.official = false'];
            $none = [];
            foreach (array_values($filters['tab_tags'] ?? []) as $i => $tag) {
                $none[] = "CONCAT(' ', COALESCE(l.tags, ''), ' ') NOT LIKE :tabtags$i";
                $qb->setParameter("tabtags$i", '% ' . addcslashes($tag, '%_') . ' %');
            }
            if ($none) {
                $untagged[] = '(' . implode(' AND ', $none) . ')';
            }
            $qb->andWhere(implode(' OR ', $untagged));
        }
    }

    /** @return array<string, int> the tags on the owner's libraries, or the public ones, with how many carry each */
    public function countTags(?User $owner): array
    {
        $qb = $this->createQueryBuilder('l')->select('l.tags')->andWhere('l.tags IS NOT NULL');
        $owner !== null ? $qb->andWhere('l.user = :owner')->setParameter('owner', $owner)
            : $qb->andWhere('l.visibility = :public')->setParameter('public', Library::PUBLIC);
        $counts = [];
        foreach ($qb->getQuery()->getSingleColumnResult() as $tags) {
            foreach (array_unique(preg_split('/\s+/', trim((string) $tags), -1, PREG_SPLIT_NO_EMPTY)) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    /** The number a library takes when it first goes public */
    public function nextPublicId(): int
    {
        return 1 + (int) $this->createQueryBuilder('l')->select('MAX(l.publicId)')->getQuery()->getSingleScalarResult();
    }

    /**
     * The Add to Library drawer's list: the user's libraries, most recently changed first,
     * narrowed by name
     * @return Library[]
     */
    public function picker(User $user, string $q, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('l')->leftJoin('l.entries', 'e')->addSelect('e')
            ->andWhere('l.user = :user')->setParameter('user', $user)
            ->orderBy('l.dateUpdate', 'DESC')->addOrderBy('l.id', 'DESC');
        if (trim($q) !== '') {
            $qb->andWhere('l.name LIKE :q')->setParameter('q', '%' . addcslashes(trim($q), '%_') . '%');
        }
        return array_slice($qb->getQuery()->getResult(), 0, $limit);
    }
}
