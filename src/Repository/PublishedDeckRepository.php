<?php

namespace App\Repository;

use App\Entity\PublishedDeckInterface;
use Doctrine\ORM\EntityRepository;

class PublishedDeckRepository extends EntityRepository
{
    public function findDuplicate(PublishedDeckInterface $decklist)
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d, f')
            ->join('d.faction', 'f')
            ->andWhere('d.signature = ?1');

        $qb->setParameter(1, $decklist->getSignature());
        $qb->orderBy('d.dateCreation', 'ASC');
        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    //findBy([ 'parent' => $decklist->getParent() ], [ 'version' => 'DESC' ]);
    public function findVersions(PublishedDeckInterface $decklist)
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d, f, ds, c')
            ->join('d.faction', 'f')
            ->join('d.slots', 'ds')
            ->join('ds.card', 'c')
            ->andWhere('d.parent = ?1');

        $qb->setParameter(1, $decklist->getParent());
        $qb->orderBy('d.version', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
