<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\DaySlot;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationService;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use App\Service\Calibration;
use App\Service\DateLabels;
use App\Service\HouseholdProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RecommendationController extends AbstractController
{
    /** Au-delà, les prévisions sont trop incertaines pour s'y fier : les lignes sont grisées. */
    private const RELIABLE_DAYS = 7;

    /** Nombre de créneaux mis en avant en tête de page. */
    private const UPCOMING = 4;

    #[Route('/recommandations', name: 'app_recommendations', methods: ['GET'])]
    public function index(
        Request $request,
        HouseholdProvider $households,
        PlaceRepository $placeRepository,
        RecommendationService $recommendations,
        RecommendationEngine $engine,
        ReadingRepository $readings,
        ClockInterface $clock,
        DateLabels $labels,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);
        $analysis = $recommendations->analyze($household);
        $timezone = new \DateTimeZone($household->getTimezone());

        // Dès qu'une pièce a des températures visées propres, toutes les pièces ont leur recommandation.
        $places = $placeRepository->findForRecommendations($household);
        $selected = $this->selectedPlace($request, $places);

        $set = null;
        $error = null;
        try {
            $set = $recommendations->forHouseholdAndPlaces($household, $places, $analysis);
        } catch (HouseholdNotLocatedException) {
            // Signalé par le gabarit : invitation à renseigner la ville.
        } catch (ForecastUnavailableException) {
            $error = 'Les prévisions sont momentanément indisponibles. Réessayez dans quelques minutes.';
        }

        $householdDays = $set->household ?? [];
        $days = null !== $selected ? ($set->places[(int) $selected->getId()] ?? []) : $householdDays;

        $dayLabel = static fn (\DateTimeInterface $date): string => $labels->shortDay($date, $household->getTimezone());
        $now = new \DateTimeImmutable($clock->now()->setTimezone($timezone)->format('Y-m-d H:i:s'));

        $rows = [];
        foreach ($days as $index => $day) {
            $rows[] = [
                'label' => $dayLabel($day->date),
                'day' => $day,
                'distant' => $index >= self::RELIABLE_DAYS,
            ];
        }
        $label = fn ($slot): string => $slot->recommendation->slot->label().' · '.$dayLabel($slot->date);
        $upcoming = array_map(
            static fn ($slot): array => ['label' => $label($slot), 'item' => $slot->recommendation],
            $engine->upcoming($days, $now, self::UPCOMING, $analysis->heating, $selected?->getName()),
        );

        // Pièce par pièce, pour les mêmes créneaux que le foyer entier : seulement les pièces à températures propres.
        $placeRows = [];
        $columns = [];
        if (null === $selected && [] !== $places && null !== $set) {
            $columns = array_map(static fn ($slot): string => $label($slot), $engine->upcoming($householdDays, $now, self::UPCOMING));
            foreach ($places as $place) {
                $placeRows[] = [
                    'place' => $place,
                    'hasReadings' => $analysis->hasReadingsFor($place->getName()),
                    'cells' => array_map(static fn ($slot) => $slot->recommendation, $engine->upcoming($set->places[(int) $place->getId()], $now, self::UPCOMING, $analysis->heating, $place->getName())),
                ];
            }
        }

        return $this->render('recommendation/index.html.twig', [
            'household' => $household,
            'located' => null !== $household->getLatitude(),
            'hasReadings' => !$analysis->report->isEmpty(),
            'daysDone' => $readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
            'rows' => $rows,
            'upcoming' => $upcoming,
            'places' => $places,
            'selected' => $selected,
            'selectedHasReadings' => null !== $selected && $analysis->hasReadingsFor($selected->getName()),
            'placeRows' => $placeRows,
            'placeColumns' => $columns,
            'slots' => DaySlot::chronological(),
            'error' => $error,
        ]);
    }

    /**
     * La pièce demandée par ?piece=<id>, parmi celles du foyer : un identifiant inconnu, ou celui d'un
     * autre foyer, est une 404 (une pièce d'autrui n'est pas distinguée d'une pièce qui n'existe pas).
     *
     * @param list<\App\Entity\Place> $places
     */
    private function selectedPlace(Request $request, array $places): ?\App\Entity\Place
    {
        $id = $request->query->get('piece');
        if (null === $id || '' === $id) {
            return null;
        }

        foreach ($places as $place) {
            if ((string) $place->getId() === (string) $id) {
                return $place;
            }
        }

        throw new NotFoundHttpException('Pièce introuvable.');
    }
}
