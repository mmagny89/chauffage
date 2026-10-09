<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReadingSessionInput;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Exception\ReadingsRejectedException;
use App\Form\ReadingSessionType;
use App\Repository\ReadingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enregistre un relevé : un enregistrement par lieu déclaré, à la même heure, avec la
 * même température extérieure. Refuse l'avenir et les doublons, et n'écrit rien si
 * une seule ligne est refusée.
 */
final readonly class ReadingRecorder
{
    public function __construct(
        private ReadingRepository $readings,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Place> $places lieux déclarés du foyer : tous doivent être renseignés
     *
     * @return int nombre de relevés enregistrés
     *
     * @throws ReadingsRejectedException motifs indexés par nom de champ (« time » ou « p<id> »)
     */
    public function record(Household $household, ReadingSessionInput $input, array $places): int
    {
        \assert(null !== $input->date && null !== $input->time && null !== $input->outdoor);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone($household->getTimezone()));
        $nowWallClock = new \DateTimeImmutable($now->format('Y-m-d H:i:s'));
        $measuredAt = $input->date->setTime((int) $input->time->format('G'), (int) $input->time->format('i'));

        if ($measuredAt > $nowWallClock) {
            throw new ReadingsRejectedException(['time' => 'Cette heure est dans le futur.']);
        }

        $reasons = [];
        $pending = [];
        foreach ($places as $place) {
            $field = ReadingSessionType::fieldName($place);
            $indoor = $input->indoor[$field] ?? null;
            \assert(null !== $indoor);

            if ($this->readings->existsFor($place, $measuredAt)) {
                $reasons[$field] = 'Un relevé existe déjà pour ce lieu à cette heure.';
                continue;
            }
            $pending[] = new Reading($place, $measuredAt, $input->outdoor, $indoor);
        }

        if ([] !== $reasons) {
            throw new ReadingsRejectedException($reasons);
        }

        foreach ($pending as $reading) {
            $this->entityManager->persist($reading);
        }
        $this->entityManager->flush();

        return \count($pending);
    }
}
