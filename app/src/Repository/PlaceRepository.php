<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\Place;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Place>
 */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    /**
     * Insensible à la casse : « salon » et « Salon » désignent le même lieu.
     */
    public function findOneByHouseholdAndName(Household $household, string $name): ?Place
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.household = :household')
            ->andWhere('LOWER(p.name) = :name')
            ->setParameter('household', $household)
            ->setParameter('name', mb_strtolower(trim($name)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<string>
     */
    public function namesOf(Household $household): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.name')
            ->andWhere('p.household = :household')
            ->setParameter('household', $household)
            ->orderBy('p.name')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }
}
