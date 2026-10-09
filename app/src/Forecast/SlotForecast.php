<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enum\DaySlot;

/**
 * Température extérieure prévue sur un créneau : moyenne, minimale et maximale.
 */
final readonly class SlotForecast
{
    public function __construct(
        public DaySlot $slot,
        public ?float $average,
        public ?float $min,
        public ?float $max,
        public int $hours,
        public int $expectedHours,
    ) {
    }

    /**
     * Faux quand l'horizon des prévisions coupe le créneau : la moyenne porte alors sur moins d'heures.
     */
    public function isComplete(): bool
    {
        return $this->hours === $this->expectedHours;
    }

    public function hasData(): bool
    {
        return null !== $this->average;
    }
}
