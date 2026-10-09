<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CityChoiceInput;
use App\Dto\TargetsInput;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Form\WeekTargetsType;
use App\Geocoding\GeocoderInterface;
use App\Geocoding\GeocodingUnavailableException;
use App\Repository\PlaceRepository;
use App\Service\HouseholdProvider;
use App\Service\RoomCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/reglages')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly HouseholdProvider $households,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $geocodingLimiter,
        private readonly PlaceRepository $placeRepository,
        private readonly RoomCatalog $rooms,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, GeocoderInterface $geocoder, #[CurrentUser] User $user): Response
    {
        $household = $this->households->forUser($user);

        /** @var array<string, TargetsInput> $week */
        $week = [];
        foreach (Weekday::cases() as $day) {
            $targets = new TargetsInput();
            foreach (DaySlot::cases() as $slot) {
                $targets->{$slot->value} = $household->targetFor($day, $slot)->getTemperature();
            }
            $week[$day->key()] = $targets;
        }
        $form = $this->createForm(WeekTargetsType::class, $week);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (Weekday::cases() as $day) {
                foreach (DaySlot::cases() as $slot) {
                    $household->targetFor($day, $slot)->setTemperature((float) $week[$day->key()]->for($slot));
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Températures visées enregistrées.');

            return $this->redirectToRoute('app_settings');
        }

        $query = trim((string) $request->query->get('q', ''));
        $results = [];
        $searchError = null;
        if ('' !== $query) {
            if (!$this->geocodingLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $searchError = 'Trop de recherches : réessayez dans quelques minutes.';
            } else {
                try {
                    $results = $geocoder->search($query);
                } catch (GeocodingUnavailableException) {
                    $searchError = 'La recherche de ville est momentanément indisponible. Réessayez plus tard.';
                }
            }
        }

        $places = $this->placeRepository->findByHousehold($household);

        return $this->render('settings/index.html.twig', [
            'household' => $household,
            'places' => $places,
            'placeChoices' => $this->rooms->availableChoices(array_map(static fn ($p): string => $p->getName(), $places)),
            'form' => $form,
            'slots' => DaySlot::chronological(),
            'weekdays' => Weekday::cases(),
            'query' => $query,
            'results' => $results,
            'searchError' => $searchError,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/ville', name: 'app_settings_city', methods: ['POST'])]
    public function chooseCity(Request $request, ValidatorInterface $validator, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('choose-city', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Relancez la recherche.');

            return $this->redirectToRoute('app_settings');
        }

        $choice = new CityChoiceInput();
        $choice->label = (string) $request->request->get('label');
        $choice->latitude = is_numeric($request->request->get('latitude')) ? (float) $request->request->get('latitude') : null;
        $choice->longitude = is_numeric($request->request->get('longitude')) ? (float) $request->request->get('longitude') : null;
        $choice->timezone = (string) $request->request->get('timezone');

        if (\count($validator->validate($choice)) > 0) {
            $this->addFlash('error', 'Cette ville n’a pas pu être enregistrée. Relancez la recherche.');

            return $this->redirectToRoute('app_settings');
        }

        \assert(null !== $choice->latitude && null !== $choice->longitude);
        $this->households->forUser($user)->locate($choice->label, $choice->latitude, $choice->longitude, $choice->timezone);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Ville enregistrée : %s.', $choice->label));

        return $this->redirectToRoute('app_settings');
    }
}
