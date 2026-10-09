<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Modèle de l'écart intérieur − extérieur en fonction de la température extérieure :
 * écart(T) = ordonnée + pente × T. Pour un écart moyen constant, la pente est nulle.
 */
final readonly class DeltaModel
{
    /** Marge, en °C, autour des températures mesurées au-delà de laquelle on extrapole. */
    public const EXTRAPOLATION_MARGIN = 3.0;

    /** Raison pour laquelle la régression n'a pas pu être retenue. */
    public const REFUSAL_FEW_SESSIONS = 'sessions';
    public const REFUSAL_NARROW_RANGE = 'range';

    public function __construct(
        public ModelKind $kind,
        public float $intercept,
        public float $slope,
        public int $readings,
        public int $sessions,
        public float $outdoorMin,
        public float $outdoorMax,
        public ?string $refusal = null,
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
