<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le foyer d'un compte. Il est créé à l'inscription ; l'accès le recrée pour un
 * compte qui en serait dépourvu (créé avant le modèle, ou à la main).
 */
final readonly class HouseholdProvider
{
    public function __construct(
        private HouseholdRepository $households,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function forUser(User $user): Household
    {
        $household = $this->households->findOneBy(['user' => $user]);
        if (null === $household) {
            $household = new Household($user);
            $this->entityManager->persist($household);
            $this->entityManager->flush();
        }

        return $household;
    }
}
