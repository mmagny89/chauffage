<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calculation\DeltaCalculator;
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
        DeltaCalculator $calculator,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);

        return $this->render('delta/index.html.twig', [
            'report' => $calculator->calculate($readings->findByHousehold($household)),
            'slots' => DaySlot::chronological(),
            'daysDone' => $readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
        ]);
    }
}
