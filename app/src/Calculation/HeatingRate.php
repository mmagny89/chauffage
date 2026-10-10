<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Vitesse de montée en température d'une pièce chauffée, en °C par heure.
 */
final readonly class HeatingRate
{
    public function __construct(
        public float $degreesPerHour,
        /** Nombre d'allumages sur lesquels elle repose. */
        public int $samples,
    ) {
    }

    /**
     * Minutes pour monter de $degrees, arrondies au 5 min supérieur.
     */
    public function minutesToRise(float $degrees): int
    {
        return (int) (ceil($degrees / $this->degreesPerHour * 60 / 5) * 5);
    }
}
