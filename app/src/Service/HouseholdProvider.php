<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le foyer d'un compte. Il est créé à l'inscription ; l'accès le recrée pour un compte
 * qui en serait dépourvu, et complète les températures visées qui lui manqueraient
 * (foyer créé à la main, ou créneau ajouté depuis).
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
        } elseif ($household->completeTargets() > 0) {
            $this->entityManager->flush();
        }

        return $household;
    }
}
