<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Modèle de l'écart intérieur − extérieur en fonction de la température extérieure :
 * écart(T) = ordonnée + pente × T.
 */
final readonly class DeltaModel
{
    /** Marge, en °C, autour des températures mesurées au-delà de laquelle on extrapole. */
    public const EXTRAPOLATION_MARGIN = 3.0;

    /**
     * @param float $dataWeight part des relevés dans la pente, de 0 (pente typique pure) à 1
     */
    public function __construct(
        public ModelKind $kind,
        public float $intercept,
        public float $slope,
        public int $readings,
        public int $sessions,
        public float $outdoorMin,
        public float $outdoorMax,
        public float $dataWeight,
    ) {
    }

    public function deltaAt(float $outdoor): float
    {
        return $this->intercept + $this->slope * $outdoor;
    }

    /**
     * Vrai si la température demandée est dans la plage des relevés (à la marge près) :
     * en dehors, le modèle extrapole.
     */
    public function covers(float $outdoor): bool
    {
        return $outdoor >= $this->outdoorMin - self::EXTRAPOLATION_MARGIN
            && $outdoor <= $this->outdoorMax + self::EXTRAPOLATION_MARGIN;
    }
}
