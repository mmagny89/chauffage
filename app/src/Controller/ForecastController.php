<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\DaySlot;
use App\Forecast\ForecastService;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;
use App\Service\HouseholdProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ForecastController extends AbstractController
{
    #[Route('/previsions', name: 'app_forecast', methods: ['GET'])]
    public function index(HouseholdProvider $households, ForecastService $forecasts, #[CurrentUser] User $user): Response
    {
        $household = $households->forUser($user);

        $days = [];
        $error = null;
        try {
            $days = $forecasts->forHousehold($household);
        } catch (HouseholdNotLocatedException) {
            // Affiché par le gabarit : invitation à renseigner la ville.
        } catch (ForecastUnavailableException) {
            $error = 'Les prévisions sont momentanément indisponibles. Réessayez dans quelques minutes.';
        }

        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $household->getTimezone(), null, 'EEE d MMM');
        $rows = array_map(
            static fn ($day): array => ['label' => (string) $formatter->format($day->date), 'day' => $day],
            $days,
        );

        return $this->render('forecast/index.html.twig', [
            'household' => $household,
            'located' => null !== $household->getLatitude(),
            'rows' => $rows,
            'slots' => DaySlot::chronological(),
            'error' => $error,
        ]);
    }
}
