<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\Reading;

/**
 * Cherche la pente typique qui aurait le mieux prédit les relevés du foyer : chaque pente candidate est
 * évaluée comme la page Fiabilité le fait (chaque séance estimée sans elle-même), et l'on retient celle
 * dont l'erreur absolue moyenne est la plus petite.
 *
 * La pente typique ne pèse que tant que les relevés sont peu variés (voir DeltaModelFitter) : avec des
 * relevés très variés toutes les pentes se valent, et rien n'est proposé. On ne change de pente que pour
 * un gain d'au moins MIN_GAIN, pour ne pas suivre le bruit. Calcul pur.
 */
final readonly class SlopeTuner
{
    /** Gain minimal d'erreur moyenne, en °C, pour proposer une autre pente. */
    public const MIN_GAIN = 0.1;

    /** Pas de la recherche. */
    private const STEP = 0.1;

    public function __construct(private AccuracyEvaluator $evaluator)
    {
    }

    /**
     * @param list<Reading> $readings
     * @param float         $current  pente actuellement appliquée
     */
    public function tune(array $readings, float $current): SlopeTuning
    {
        $report = $this->evaluator->evaluate($readings, $current);
        if ($report->isProvisional() || null === $report->overall) {
            return new SlopeTuning($current, $current, $report->overall?->meanAbsoluteError, $report->overall?->meanAbsoluteError, false);
        }

        $currentError = $report->overall->meanAbsoluteError;
        $best = $current;
        $bestError = $currentError;
        for ($slope = DeltaModelFitter::SLOPE_MAX; $slope >= DeltaModelFitter::SLOPE_MIN - 1e-9; $slope -= self::STEP) {
            $candidate = round($slope, 1);
            $overall = $this->evaluator->evaluate($readings, $candidate)->overall;
            // Strictement meilleur d'au moins MIN_GAIN que le meilleur retenu jusque-là (la pente actuelle d'abord).
            if (null !== $overall && $overall->meanAbsoluteError <= $bestError - self::MIN_GAIN + 1e-9) {
                $best = $candidate;
                $bestError = $overall->meanAbsoluteError;
            }
        }

        return new SlopeTuning($current, $best, $currentError, $bestError, true);
    }
}
