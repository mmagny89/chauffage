<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calculation\AccuracyEvaluator;
use App\Calculation\DeltaModelFitter;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Repository\ReadingRepository;
use App\Security\AuditLogger;
use App\Service\HouseholdProvider;
use App\Service\TypicalSlopeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AccuracyController extends AbstractController
{
    #[Route('/fiabilite', name: 'app_accuracy', methods: ['GET'])]
    public function index(
        HouseholdProvider $households,
        ReadingRepository $readings,
        AccuracyEvaluator $evaluator,
        TypicalSlopeResolver $slopes,
        #[CurrentUser] User $user,
    ): Response {
        $household = $households->forUser($user);
        $all = $readings->findByHousehold($household);

        return $this->render('accuracy/index.html.twig', [
            'report' => $evaluator->evaluate($all, $slopes->effective($household, $all)),
            'tuning' => $slopes->tuning($household, $all),
            'auto' => $household->isAutoTuneSlope(),
            'custom' => null !== $household->getTypicalSlope(),
            'defaultSlope' => DeltaModelFitter::TYPICAL_SLOPE,
            'slots' => DaySlot::chronological(),
            'sessionsRequired' => AccuracyEvaluator::RELIABLE_FROM_SESSIONS,
        ]);
    }

    /**
     * Règle la pente typique du foyer : l'appliquer à la valeur proposée, passer en réglage automatique,
     * ou revenir à la valeur par défaut.
     */
    #[Route('/fiabilite/pente', name: 'app_accuracy_slope', methods: ['POST'])]
    public function slope(
        Request $request,
        HouseholdProvider $households,
        ReadingRepository $readings,
        TypicalSlopeResolver $slopes,
        EntityManagerInterface $entityManager,
        AuditLogger $audit,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('accuracy-slope', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Réessayez.');

            return $this->redirectToRoute('app_accuracy');
        }

        $household = $households->forUser($user);
        $action = (string) $request->request->get('action');

        switch ($action) {
            case 'apply':
                $tuning = $slopes->tuning($household, $readings->findByHousehold($household));
                if (!$tuning->isWorthApplying() || $household->isAutoTuneSlope()) {
                    $this->addFlash('error', 'Aucune pente à appliquer.');

                    return $this->redirectToRoute('app_accuracy');
                }
                $household->setTypicalSlope($tuning->best)->setAutoTuneSlope(false);
                $this->addFlash('success', 'Pente typique réglée.');
                break;
            case 'auto_on':
                $household->setAutoTuneSlope(true);
                $this->addFlash('success', 'Réglage automatique activé.');
                break;
            case 'reset':
                $household->setTypicalSlope(null)->setAutoTuneSlope(false);
                $this->addFlash('success', 'Pente typique par défaut rétablie.');
                break;
            default:
                $this->addFlash('error', 'Action inconnue.');

                return $this->redirectToRoute('app_accuracy');
        }

        $entityManager->flush();
        $audit->info('slope_setting_changed', ['user_id' => $user->getId(), 'action' => $action]);

        return $this->redirectToRoute('app_accuracy');
    }
}
