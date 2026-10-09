<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationService;
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
        DateLabels $labels,
        ClockInterface $clock,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);
        $analysis = $recommendations->analyze($household);
        $hasReadings = !$analysis->report->isEmpty();
        $timezone = new \DateTimeZone($household->getTimezone());

        // Le prochain créneau : une panne de prévisions ne doit pas priver l'accueil du reste.
        $upcoming = [];
        if ($hasReadings) {
            try {
                $now = new \DateTimeImmutable($clock->now()->setTimezone($timezone)->format('Y-m-d H:i:s'));
                foreach ($engine->upcoming($recommendations->forHousehold($household, $analysis), $now, self::UPCOMING) as $slot) {
                    $upcoming[] = [
                        'label' => $slot->recommendation->slot->label().' · '.$labels->shortDay($slot->date, $household->getTimezone()),
                        'item' => $slot->recommendation,
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
        ]);
    }
}
