<?php

declare(strict_types=1);

namespace App\Forecast;

/**
 * Température prévue pour une heure, à l'heure murale locale du foyer (sans fuseau).
 */
final readonly class HourlyTemperature
{
    public function __construct(
        public \DateTimeImmutable $at,
        public float $celsius,
    ) {
    }
}
