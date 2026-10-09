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
     * Lieux du foyer, triés par nom (ordre alphabétique français).
     *
     * @return list<Place>
     */
    public function findByHousehold(Household $household): array
    {
        /** @var list<Place> $places */
        $places = $this->findBy(['household' => $household]);
        $collator = new \Collator('fr_FR');
        usort($places, static fn (Place $a, Place $b): int => $collator->compare($a->getName(), $b->getName()));

        return $places;
    }
}
