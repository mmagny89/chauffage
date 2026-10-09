<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\HouseholdProvider;
use App\Service\SetupChecklist;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Fin de la mise en route : on ne passe aux relevés qu'une fois la ville choisie et un lieu
 * déclaré, et après avoir relu les températures visées.
 */
final class SetupController extends AbstractController
{
    #[Route('/reglages/terminer', name: 'app_setup_finish', methods: ['POST'])]
    public function finish(
        Request $request,
        HouseholdProvider $households,
        SetupChecklist $checklist,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('finish-setup', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Réessayez.');

            return $this->redirectToRoute('app_settings');
        }

        $household = $households->forUser($user);
        if ($household->isSetUp()) {
            return $this->redirectToRoute('app_readings');
        }

        $missing = $checklist->progress($household)->missing();
        if ([] !== $missing) {
            $this->addFlash('error', 'Pour terminer la mise en route : '.implode(', ', $missing).'.');

            return $this->redirectToRoute('app_settings');
        }

        $household->completeSetup(new \DateTimeImmutable($clock->now()->format('Y-m-d H:i:s')));
        $entityManager->flush();
        $this->addFlash('success', 'Mise en route terminée. Vous pouvez saisir votre premier relevé, chauffage éteint.');

        return $this->redirectToRoute('app_readings');
    }
}
