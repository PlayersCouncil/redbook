<?php

namespace App\Repository;

use App\Entity\Format;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Format>
 */
class FormatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Format::class);
    }

    /** The formats a deck can target: Gemp's hall formats, in Gemp's order */
    public function findSelectable(): array
    {
        return $this->findBy(['hall' => true, 'retired' => false], ['position' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneByCode(string $code): ?Format
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** @return array<string, Format> by code */
    public function findAllByCode(): array
    {
        $formats = [];
        foreach ($this->findBy([], ['position' => 'ASC', 'name' => 'ASC']) as $format) {
            $formats[$format->getCode()] = $format;
        }
        return $formats;
    }
}
