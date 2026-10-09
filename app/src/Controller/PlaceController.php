<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Place;
use App\Entity\User;
use App\Exception\PlaceRejectedException;
use App\Security\Voter\PlaceVoter;
use App\Service\HouseholdProvider;
use App\Service\PlaceManager;
use App\Service\RoomCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Déclaration des lieux du foyer, depuis la page des réglages.
 */
#[Route('/reglages/lieux')]
final class PlaceController extends AbstractController
{
    public function __construct(private readonly PlaceManager $manager)
    {
    }

    #[Route('', name: 'app_place_add', methods: ['POST'])]
    public function add(Request $request, HouseholdProvider $households, #[CurrentUser] User $user): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('place-add', (string) $request->request->get('_token'))) {
            return $this->expired();
        }

        $choice = (string) $request->request->get('choice');
        $name = RoomCatalog::OTHER === $choice ? (string) $request->request->get('custom') : $choice;

        try {
            $place = $this->manager->add($households->forUser($user), $name);
            $this->addFlash('success', \sprintf('Lieu ajouté : %s.', $place->getName()));
        } catch (PlaceRejectedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_settings', ['_fragment' => 'lieux']);
    }

    #[Route('/{id}/renommer', name: 'app_place_rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rename(Place $place, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(PlaceVoter::MANAGE, $place);
        if (!$this->isCsrfTokenValid('place-rename-'.$place->getId(), (string) $request->request->get('_token'))) {
            return $this->expired();
        }

        try {
            $this->manager->rename($place, (string) $request->request->get('name'));
            $this->addFlash('success', 'Lieu renommé.');
        } catch (PlaceRejectedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_settings', ['_fragment' => 'lieux']);
    }

    #[Route('/{id}/supprimer', name: 'app_place_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Place $place, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(PlaceVoter::MANAGE, $place);
        if (!$this->isCsrfTokenValid('place-delete-'.$place->getId(), (string) $request->request->get('_token'))) {
            return $this->expired();
        }

        $name = $place->getName();
        $this->manager->delete($place);
        $this->addFlash('success', \sprintf('Lieu supprimé : %s, avec ses relevés.', $name));

        return $this->redirectToRoute('app_settings', ['_fragment' => 'lieux']);
    }

    private function expired(): RedirectResponse
    {
        $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Réessayez.');

        return $this->redirectToRoute('app_settings', ['_fragment' => 'lieux']);
    }
}
