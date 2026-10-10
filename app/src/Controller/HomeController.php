<?php

declare(strict_types=1);

namespace App\Controller;

use App\Alert\FrostAdvisor;
use App\Entity\User;
use App\Forecast\ForecastService;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationService;
use App\Recommendation\SlotRecommendation;
use App\Recommendation\UpcomingSlot;
use App\Reminder\ReadingReminder;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use App\Service\Calibration;
use App\Service\DateLabels;
use App\Service\HouseholdProvider;
use App\Shutter\ShutterService;
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
        ShutterService $shutters,
        ForecastService $forecasts,
        FrostAdvisor $frostAdvisor,
        ReadingReminder $reminders,
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
        $now = new \DateTimeImmutable($clock->now()->setTimezone($timezone)->format('Y-m-d H:i:s'));

        // Gel annoncé et rappel de relevé : les deux lisent les prévisions, mais ne dépendent pas des relevés.
        $forecast = [];
        try {
            $forecast = $forecasts->forHousehold($household);
        } catch (HouseholdNotLocatedException|ForecastUnavailableException) {
            // Sans prévision : ni alerte de froid, ni rappel lié aux températures annoncées.
        }
        $daysDone = $readings->countDays($household);

        // Le prochain créneau : une panne de prévisions ne doit pas priver l'accueil du reste.
        $upcoming = [];
        $rooms = [];
        if ($hasReadings) {
            try {
                // Dès qu'une pièce a des températures visées propres, toutes les pièces ont leur recommandation.
                $tracked = $placeRepository->findForRecommendations($household);
                $set = $recommendations->forHouseholdAndPlaces($household, $tracked, $analysis);
                $timezoneName = $household->getTimezone();
                $upcoming = $this->entries($engine->upcoming($set->household, $now, self::UPCOMING, $analysis->heating, null, $analysis->rates), $labels, $timezoneName);
                foreach ($tracked as $place) {
                    $rooms[] = [
                        'place' => $place,
                        'hasReadings' => $analysis->hasReadingsFor($place->getName()),
                        'slots' => $this->entries($engine->upcoming($set->places[(int) $place->getId()], $now, self::UPCOMING, $analysis->heating, $place->getName(), $analysis->rates), $labels, $timezoneName),
                    ];
                }
            } catch (HouseholdNotLocatedException|ForecastUnavailableException) {
                // Le tableau de bord reste utile sans la prévision.
            }
        }

        // Indépendant des relevés : il suffit que le foyer ait une ville.
        $shutterAdvice = [];
        try {
            $shutterAdvice = $shutters->forHousehold($household);
        } catch (HouseholdNotLocatedException|ForecastUnavailableException) {
            // Pas d'indication plutôt qu'un tableau de bord cassé.
        }

        return $this->render('home/index.html.twig', [
            'shutters' => $shutterAdvice,
            'household' => $household,
            'frost' => $frostAdvisor->advise($forecast, $now),
            'reminder' => $reminders->remind($daysDone, $analysis->lastReadingAt, $now, $analysis->models->overall, $forecast),
            'daysDone' => $daysDone,
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
