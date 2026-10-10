<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Les vitesses de chauffe mesurées d'un foyer : une par pièce ayant assez d'allumages, et une pour le foyer.
 */
final readonly class HeatingRates
{
    /**
     * @param array<string, HeatingRate> $byPlace indexé par le nom de la pièce en minuscules
     */
    public function __construct(
        public ?HeatingRate $household = null,
        public array $byPlace = [],
        /** Allumages dont la consigne a été notée atteinte, utilisés ou non. */
        public int $completed = 0,
    ) {
    }

    /**
     * La vitesse de la pièce, sinon celle du foyer ; null pour le foyer entier ($placeName null) sans mesure.
     */
    public function forPlace(?string $placeName): ?HeatingRate
    {
        return null === $placeName ? $this->household : ($this->byPlace[mb_strtolower($placeName)] ?? $this->household);
    }
}
