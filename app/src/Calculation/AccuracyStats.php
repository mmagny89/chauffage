<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Erreur d'estimation sur un ensemble de relevés. L'erreur d'un relevé est l'intérieur estimé moins
 * l'intérieur mesuré : positive, le modèle annonçait trop chaud.
 */
final readonly class AccuracyStats
{
    /**
     * @param float $meanAbsoluteError erreur absolue moyenne, en °C
     * @param float $bias              erreur moyenne signée, en °C
     * @param float $withinTolerance   part des relevés estimés à la tolérance près, de 0 à 1
     */
    public function __construct(
        public int $count,
        public float $meanAbsoluteError,
        public float $bias,
        public float $withinTolerance,
    ) {
    }

    /**
     * @param non-empty-list<int> $errorsTenths erreurs, en dixièmes de degré
     */
    public static function fromErrors(array $errorsTenths, int $toleranceTenths): self
    {
        $count = \count($errorsTenths);

        return new self(
            $count,
            round(array_sum(array_map(abs(...), $errorsTenths)) / $count / 10, 1),
            round(array_sum($errorsTenths) / $count / 10, 1),
            \count(array_filter($errorsTenths, static fn (int $e): bool => abs($e) <= $toleranceTenths)) / $count,
        );
    }

    /**
     * Vrai quand l'erreur moyenne signée atteint 1 °C : le modèle penche d'un côté.
     */
    public function isBiased(): bool
    {
        return abs($this->bias) >= 1.0;
    }
}
