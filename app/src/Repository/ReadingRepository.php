<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reading>
 */
class ReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reading::class);
    }

    public function existsFor(Place $place, \DateTimeImmutable $measuredAt): bool
    {
        return null !== $this->findOneBy(['place' => $place, 'measuredAt' => $measuredAt]);
    }

    /**
     * Relevés du foyer, les plus récents d'abord.
     *
     * @return list<Reading>
     */
    public function findByHousehold(Household $household): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p')
            ->join('r.place', 'p')
            ->andWhere('p.household = :household')
            ->setParameter('household', $household)
            ->orderBy('r.measuredAt', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de jours distincts qui portent au moins un relevé.
     */
    public function countDays(Household $household): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT CAST(r.measured_at AS DATE)) FROM reading r JOIN place p ON p.id = r.place_id WHERE p.household_id = :household',
            ['household' => $household->getId()],
        );
    }
}
