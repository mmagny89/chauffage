<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ReadingSessionInput;
use App\Entity\HeatingStart;
use App\Entity\Reading;
use App\Entity\User;
use App\Exception\ReadingsRejectedException;
use App\Form\ReadingSessionType;
use App\Repository\HeatingStartRepository;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use App\Security\Voter\HeatingStartVoter;
use App\Security\Voter\ReadingVoter;
use App\Service\Calibration;
use App\Service\DateLabels;
use App\Service\HouseholdProvider;
use App\Service\ReadingRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/releves')]
final class ReadingController extends AbstractController
{
    public function __construct(
        private readonly HouseholdProvider $households,
        private readonly ReadingRepository $readings,
        private readonly PlaceRepository $places,
        private readonly HeatingStartRepository $heatingStarts,
    ) {
    }

    #[Route('', name: 'app_readings', methods: ['GET', 'POST'])]
    public function index(Request $request, ReadingRecorder $recorder, ClockInterface $clock, DateLabels $labels, #[CurrentUser] User $user): Response
    {
        $household = $this->households->forUser($user);
        $places = $this->places->findByHousehold($household);

        $form = null;
        $status = Response::HTTP_OK;
        if ([] !== $places) {
            $input = new ReadingSessionInput();
            $input->date = new \DateTimeImmutable($clock->now()->setTimezone(new \DateTimeZone($household->getTimezone()))->format('Y-m-d'));

            $form = $this->createForm(ReadingSessionType::class, $input, ['places' => $places]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $recorded = $recorder->record($household, $input, $places);
                    $message = 1 === $recorded->readings ? '1 relevé enregistré.' : \sprintf('%d relevés enregistrés (un par lieu).', $recorded->readings);
                    if ([] !== $recorded->heatedPlaces) {
                        $message .= ' Chauffage noté pour : '.implode(', ', $recorded->heatedPlaces).'.';
                    }
                    $this->addFlash('success', $message);

                    return $this->redirectToRoute('app_readings');
                } catch (ReadingsRejectedException $exception) {
                    foreach ($exception->reasons as $field => $reason) {
                        $target = match (true) {
                            'time' === $field => $form->get('time'),
                            str_starts_with($field, 'h') => $form->get('heating')->get('p'.substr($field, 1)),
                            default => $form->get('indoor')->get($field),
                        };
                        $target->addError(new FormError($reason));
                    }
                }
            }
            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        }

        $days = [];
        foreach ($this->readings->findByHousehold($household) as $reading) {
            $key = $reading->getMeasuredAt()->format('Y-m-d');
            $days[$key] ??= ['label' => ucfirst($labels->fullDay($reading->getMeasuredAt(), $household->getTimezone())), 'readings' => []];
            $days[$key]['readings'][] = $reading;
        }

        return $this->render('reading/index.html.twig', [
            'form' => $form,
            'heatingStarts' => $this->heatingStarts->findByHousehold($household),
            'places' => $places,
            'days' => $days,
            'daysDone' => $this->readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
        ], new Response(status: $status));
    }

    #[Route('/{id}/supprimer', name: 'app_reading_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Reading $reading, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ReadingVoter::DELETE, $reading);

        if (!$this->isCsrfTokenValid('delete-reading-'.$reading->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Réessayez.');

            return $this->redirectToRoute('app_readings');
        }

        $entityManager->remove($reading);
        $entityManager->flush();
        $this->addFlash('success', 'Relevé supprimé.');

        return $this->redirectToRoute('app_readings');
    }

    #[Route('/chauffage/{id}/supprimer', name: 'app_heating_start_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteHeatingStart(HeatingStart $start, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(HeatingStartVoter::DELETE, $start);

        if (!$this->isCsrfTokenValid('delete-heating-start-'.$start->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : le jeton de sécurité a expiré. Réessayez.');

            return $this->redirectToRoute('app_readings', ['_fragment' => 'chauffage']);
        }

        $entityManager->remove($start);
        $entityManager->flush();
        $this->addFlash('success', 'Allumage supprimé.');

        return $this->redirectToRoute('app_readings', ['_fragment' => 'chauffage']);
    }
}
