<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\DaySlot;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationService;
use App\Repository\ReadingRepository;
use App\Service\Calibration;
use App\Service\HouseholdProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
        HouseholdProvider $households,
        RecommendationService $recommendations,
        RecommendationEngine $engine,
        ReadingRepository $readings,
        ClockInterface $clock,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);
        $analysis = $recommendations->analyze($household);
        $timezone = new \DateTimeZone($household->getTimezone());

        $days = [];
        $error = null;
        try {
            $days = $recommendations->forHousehold($household, $analysis);
        } catch (HouseholdNotLocatedException) {
            // Signalé par le gabarit : invitation à renseigner la ville.
        } catch (ForecastUnavailableException) {
            $error = 'Les prévisions sont momentanément indisponibles. Réessayez dans quelques minutes.';
        }

        $dayFormatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timezone, null, 'EEE d MMM');
        $now = new \DateTimeImmutable($clock->now()->setTimezone($timezone)->format('Y-m-d H:i:s'));

        $rows = [];
        foreach ($days as $index => $day) {
            $rows[] = [
                'label' => (string) $dayFormatter->format($day->date),
                'day' => $day,
                'distant' => $index >= self::RELIABLE_DAYS,
            ];
        }
        $upcoming = array_map(
            static fn ($slot): array => ['label' => $slot->recommendation->slot->label().' · '.$dayFormatter->format($slot->date), 'item' => $slot->recommendation],
            $engine->upcoming($days, $now, self::UPCOMING),
        );

        return $this->render('recommendation/index.html.twig', [
            'household' => $household,
            'located' => null !== $household->getLatitude(),
            'hasReadings' => !$analysis->report->isEmpty(),
            'daysDone' => $readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
            'rows' => $rows,
            'upcoming' => $upcoming,
            'slots' => DaySlot::chronological(),
            'error' => $error,
        ]);
    }
}
