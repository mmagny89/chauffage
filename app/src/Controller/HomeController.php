<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationService;
use App\Recommendation\SlotRecommendation;
use App\Recommendation\UpcomingSlot;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use App\Service\Calibration;
use App\Service\DateLabels;
use App\Service\HouseholdProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HomeController extends AbstractController
{
    /** Créneaux à venir mis en avant sur le tableau de bord. */
    private const UPCOMING = 2;

    #[Route('/', name: 'app_home')]
    public function index(
        HouseholdProvider $households,
        RecommendationService $recommendations,
        RecommendationEngine $engine,
        ReadingRepository $readings,
        PlaceRepository $placeRepository,
        DateLabels $labels,
        ClockInterface $clock,
        #[CurrentUser] ?User $user,
    ): Response {
        // Visiteur non connecté : page de présentation du projet.
        if (null === $user) {
            return $this->render('home/landing.html.twig');
        }

        $household = $households->forUser($user);
        $analysis = $recommendations->analyze($household);
        $hasReadings = !$analysis->report->isEmpty();
        $timezone = new \DateTimeZone($household->getTimezone());

        // Le prochain créneau : une panne de prévisions ne doit pas priver l'accueil du reste.
        $upcoming = [];
        $rooms = [];
        if ($hasReadings) {
            try {
                $now = new \DateTimeImmutable($clock->now()->setTimezone($timezone)->format('Y-m-d H:i:s'));
                // Dès qu'une pièce a des températures visées propres, toutes les pièces ont leur recommandation.
                $tracked = $placeRepository->findForRecommendations($household);
                $set = $recommendations->forHouseholdAndPlaces($household, $tracked, $analysis);
                $timezoneName = $household->getTimezone();
                $upcoming = $this->entries($engine->upcoming($set->household, $now, self::UPCOMING, $analysis->heating), $labels, $timezoneName);
                foreach ($tracked as $place) {
                    $rooms[] = [
                        'place' => $place,
                        'hasReadings' => $analysis->hasReadingsFor($place->getName()),
                        'slots' => $this->entries($engine->upcoming($set->places[(int) $place->getId()], $now, self::UPCOMING, $analysis->heating, $place->getName()), $labels, $timezoneName),
                    ];
                }
            } catch (HouseholdNotLocatedException|ForecastUnavailableException) {
                // Le tableau de bord reste utile sans la prévision.
            }
        }

        return $this->render('home/index.html.twig', [
            'household' => $household,
            'daysDone' => $readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
            'hasReadings' => $hasReadings,
            'upcoming' => $upcoming,
            'rooms' => $rooms,
        ]);
    }

    /**
     * @param list<UpcomingSlot> $slots
     *
     * @return list<array{label: string, item: SlotRecommendation}>
     */
    private function entries(array $slots, DateLabels $labels, string $timezone): array
    {
        return array_map(
            static fn (UpcomingSlot $slot): array => [
                'label' => $slot->recommendation->slot->label().' · '.$labels->shortDay($slot->date, $timezone),
                'item' => $slot->recommendation,
            ],
            $slots,
        );
    }
}
