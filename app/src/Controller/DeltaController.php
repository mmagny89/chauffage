<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calculation\DeltaModelFitter;
use App\Recommendation\RecommendationService;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Repository\ReadingRepository;
use App\Service\Calibration;
use App\Service\HouseholdProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DeltaController extends AbstractController
{
    #[Route('/ecarts', name: 'app_deltas', methods: ['GET'])]
    public function index(
        HouseholdProvider $households,
        ReadingRepository $readings,
        RecommendationService $recommendations,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);

        $analysis = $recommendations->analyze($household);

        return $this->render('delta/index.html.twig', [
            'report' => $analysis->report,
            'models' => $analysis->models,
            'minSessions' => DeltaModelFitter::MIN_SESSIONS,
            'minSpread' => DeltaModelFitter::MIN_SPREAD,
            'slots' => DaySlot::chronological(),
            'daysDone' => $readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
        ]);
    }
}
