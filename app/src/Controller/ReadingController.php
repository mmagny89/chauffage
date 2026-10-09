<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DayReadingsInput;
use App\Dto\ReadingRowInput;
use App\Entity\Reading;
use App\Entity\User;
use App\Exception\ReadingsRejectedException;
use App\Form\DayReadingsType;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use App\Security\Voter\ReadingVoter;
use App\Service\Calibration;
use App\Service\HouseholdProvider;
use App\Service\RoomCatalog;
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
        private readonly RoomCatalog $rooms,
    ) {
    }

    #[Route('', name: 'app_readings', methods: ['GET', 'POST'])]
    public function index(Request $request, ReadingRecorder $recorder, ClockInterface $clock, #[CurrentUser] User $user): Response
    {
        $household = $this->households->forUser($user);

        $input = new DayReadingsInput();
        $input->date = new \DateTimeImmutable($clock->now()->setTimezone(new \DateTimeZone($household->getTimezone()))->format('Y-m-d'));
        $input->rows = [new ReadingRowInput()];

        $form = $this->createForm(DayReadingsType::class, $input, [
            'place_choices' => $this->rooms->choices($this->places->namesOf($household)),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $count = $recorder->record($household, $input);
                $this->addFlash('success', 1 === $count ? '1 relevé enregistré.' : \sprintf('%d relevés enregistrés.', $count));

                return $this->redirectToRoute('app_readings');
            } catch (ReadingsRejectedException $exception) {
                foreach ($exception->reasons as $index => $reason) {
                    $form->get('rows')->get((string) $index)->get('time')->addError(new FormError($reason));
                }
            }
        }

        $dayFormatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $household->getTimezone());
        $days = [];
        foreach ($this->readings->findByHousehold($household) as $reading) {
            $key = $reading->getMeasuredAt()->format('Y-m-d');
            $days[$key] ??= ['label' => ucfirst((string) $dayFormatter->format($reading->getMeasuredAt())), 'readings' => []];
            $days[$key]['readings'][] = $reading;
        }

        return $this->render('reading/index.html.twig', [
            'form' => $form,
            'days' => $days,
            'daysDone' => $this->readings->countDays($household),
            'daysRequired' => Calibration::DAYS_REQUIRED,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
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
}
