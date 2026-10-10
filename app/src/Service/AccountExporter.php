<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\HeatingStartRepository;
use App\Repository\PlaceRepository;
use App\Repository\ReadingRepository;
use Psr\Clock\ClockInterface;

/**
 * Toutes les données qu'un compte a déposées, dans un format lisible par une machine (droit d'accès et à
 * la portabilité, RGPD art. 15 et 20). N'exporte ni le hachage du mot de passe ni rien qui concerne un
 * autre compte.
 */
final readonly class AccountExporter
{
    public function __construct(
        private HouseholdProvider $households,
        private PlaceRepository $places,
        private ReadingRepository $readings,
        private HeatingStartRepository $heatingStarts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $household = $this->households->forUser($user);

        $targets = [];
        foreach ($household->getTargets() as $target) {
            $targets[] = ['day' => $target->getDayOfWeek()->key(), 'slot' => $target->getSlot()->value, 'temperature' => $target->getTemperature()];
        }

        $readingsByPlace = [];
        foreach (array_reverse($this->readings->findByHousehold($household)) as $reading) {
            $readingsByPlace[(int) $reading->getPlace()->getId()][] = [
                'measured_at' => $reading->getMeasuredAt()->format('Y-m-d H:i'),
                'outdoor_temperature' => $reading->getOutdoorTemperature(),
                'indoor_temperature' => $reading->getIndoorTemperature(),
            ];
        }
        $startsByPlace = [];
        foreach (array_reverse($this->heatingStarts->findByHousehold($household)) as $start) {
            $startsByPlace[(int) $start->getPlace()->getId()][] = [
                'started_at' => $start->getStartedAt()->format('Y-m-d H:i'),
                'setpoint' => $start->getSetpoint(),
                'indoor_temperature' => $start->getIndoorTemperature(),
                'outdoor_temperature' => $start->getOutdoorTemperature(),
            ];
        }

        $places = [];
        foreach ($this->places->findByHousehold($household) as $place) {
            $placeTargets = [];
            foreach (\App\Enum\DaySlot::chronological() as $slot) {
                $temperature = $place->targetFor($slot);
                if (null !== $temperature) {
                    $placeTargets[$slot->value] = $temperature;
                }
            }
            $id = (int) $place->getId();
            $places[] = [
                'name' => $place->getName(),
                'targets' => $placeTargets,
                'readings' => $readingsByPlace[$id] ?? [],
                'heating_starts' => $startsByPlace[$id] ?? [],
            ];
        }

        return [
            'exported_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'account' => [
                'email' => $user->getEmail(),
                'email_verified' => $user->isVerified(),
                'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'household' => [
                'city' => $household->getCity(),
                'latitude' => $household->getLatitude(),
                'longitude' => $household->getLongitude(),
                'timezone' => $household->getTimezone(),
                'setup_completed' => $household->isSetUp(),
                'typical_slope' => $household->getTypicalSlope(),
                'auto_tune_slope' => $household->isAutoTuneSlope(),
                'targets' => $targets,
            ],
            'places' => $places,
        ];
    }
}
