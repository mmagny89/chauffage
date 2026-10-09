<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HeatingStart;
use App\Entity\Household;
use App\Entity\Place;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HeatingStart>
 */
class HeatingStartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HeatingStart::class);
    }

    public function existsFor(Place $place, \DateTimeImmutable $startedAt): bool
    {
        return null !== $this->findOneBy(['place' => $place, 'startedAt' => $startedAt]);
    }

    /**
     * Allumages du foyer, les plus récents d'abord.
     *
     * @return list<HeatingStart>
     */
    public function findByHousehold(Household $household): array
    {
        return $this->createQueryBuilder('h')
            ->addSelect('p')
            ->join('h.place', 'p')
            ->andWhere('p.household = :household')
            ->setParameter('household', $household)
            ->orderBy('h.startedAt', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
