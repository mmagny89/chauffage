<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le foyer d'un compte. Il est créé à l'inscription ; l'accès le recrée pour un compte
 * qui en serait dépourvu, et complète les températures visées qui lui manqueraient
 * (foyer créé à la main, ou créneau ajouté depuis).
 *
 * Le foyer est mémorisé le temps d'une requête (le garde de mise en route et le contrôleur le
 * demandent tous deux) ; le conteneur vide cette mémoire entre deux requêtes du worker.
 */
final class HouseholdProvider implements ResetInterface
{
    /** @var array<int, Household> foyers déjà chargés, par identifiant de compte */
    private array $loaded = [];

    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function forUser(User $user): Household
    {
        $key = (int) $user->getId();
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        $household = $this->households->findOneBy(['user' => $user]);
        if (null === $household) {
            $household = new Household($user);
            $this->entityManager->persist($household);
            $this->entityManager->flush();
        } elseif ($household->completeTargets() > 0) {
            $this->entityManager->flush();
        }

        return $this->loaded[$key] = $household;
    }

    public function reset(): void
    {
        $this->loaded = [];
    }
}
