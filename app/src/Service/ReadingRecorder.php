<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\DayReadingsInput;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Exception\ReadingsRejectedException;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enregistre les relevés d'un jour pour un foyer : retrouve ou crée les lieux,
 * refuse l'avenir et les doublons, et n'écrit rien si une seule ligne est refusée.
 */
final readonly class ReadingRecorder
{
    public function __construct(
        private PlaceRepository $places,
        private ReadingRepository $readings,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int nombre de relevés enregistrés
     *
     * @throws ReadingsRejectedException
     */
    public function record(Household $household, DayReadingsInput $input): int
    {
        \assert(null !== $input->date);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone($household->getTimezone()));
        $nowWallClock = new \DateTimeImmutable($now->format('Y-m-d H:i:s'));

        $reasons = [];
        $pending = [];
        /** @var array<string, Place> $resolved lieux déjà retrouvés ou créés dans cet envoi, par nom en minuscules */
        $resolved = [];
        foreach ($input->rows as $index => $row) {
            $placeName = $row->placeName();
            \assert(null !== $placeName && null !== $row->time && null !== $row->outdoor && null !== $row->indoor);

            $measuredAt = $input->date->setTime((int) $row->time->format('G'), (int) $row->time->format('i'));
            if ($measuredAt > $nowWallClock) {
                $reasons[$index] = 'Cette heure est dans le futur.';
                continue;
            }

            $placeKey = mb_strtolower($placeName);
            $place = $resolved[$placeKey] ??= $this->places->findOneByHouseholdAndName($household, $placeName) ?? new Place($household, $placeName);
            if (null !== $place->getId() && $this->readings->existsFor($place, $measuredAt)) {
                $reasons[$index] = 'Un relevé existe déjà pour ce lieu à cette heure.';
                continue;
            }

            $pending[] = [$place, $measuredAt, $row->outdoor, $row->indoor];
        }

        if ([] !== $reasons) {
            throw new ReadingsRejectedException($reasons);
        }

        foreach ($pending as [$place, $measuredAt, $outdoor, $indoor]) {
            $this->entityManager->persist($place);
            $this->entityManager->persist(new Reading($place, $measuredAt, $outdoor, $indoor));
        }
        $this->entityManager->flush();

        return \count($pending);
    }
}
