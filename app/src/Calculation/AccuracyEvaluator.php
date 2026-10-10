<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\Reading;
use App\Recommendation\RecommendationEngine;

/**
 * Mesure si les estimations sont justes, en rejouant le passé : pour chaque séance de relevés (même
 * instant, tous lieux), le modèle est ajusté **sans** cette séance, puis il estime l'intérieur de
 * chacun de ses relevés, que l'on compare à la mesure. Évaluer un modèle sur les relevés qui l'ont
 * ajusté flatterait le résultat.
 *
 * C'est le modèle du foyer entier qui est évalué (celui de la vue « Tout le foyer »), avec la règle
 * de la recommandation : modèle du créneau, ou modèle général faute de relevé sur ce créneau.
 * La tolérance est celle de la recommandation. Calcul pur, en dixièmes de degré entiers.
 */
final readonly class AccuracyEvaluator
{
    /** En deçà de ce nombre de séances, le résultat est donné pour provisoire. */
    public const RELIABLE_FROM_SESSIONS = 8;

    public function __construct(private DeltaModelFitter $fitter)
    {
    }

    /**
     * @param iterable<Reading> $readings
     * @param float|null        $typicalSlope pente typique du foyer ; null pour la valeur par défaut
     */
    public function evaluate(iterable $readings, ?float $typicalSlope = null): AccuracyReport
    {
        /** @var array<string, list<Reading>> $sessions */
        $sessions = [];
        foreach ($readings as $reading) {
            $sessions[$reading->getMeasuredAt()->format('Y-m-d H:i')][] = $reading;
        }

        /** @var list<int> $all */
        $all = [];
        /** @var array<string, non-empty-list<int>> $bySlot */
        $bySlot = [];
        /** @var array<string, non-empty-list<int>> $byPlace */
        $byPlace = [];
        $skipped = 0;

        foreach ($sessions as $key => $held) {
            $training = [];
            foreach ($sessions as $otherKey => $others) {
                if ($otherKey !== $key) {
                    array_push($training, ...$others);
                }
            }
            $models = $this->fitter->fit($training, $typicalSlope);

            foreach ($held as $reading) {
                $model = $models->forSlot($reading->getSlot()) ?? $models->overall;
                if (null === $model) {
                    ++$skipped;
                    continue;
                }
                $outdoorTenths = (int) round($reading->getOutdoorTemperature() * 10);
                $estimated = $outdoorTenths + (int) round($model->deltaAt($reading->getOutdoorTemperature()) * 10);
                $error = $estimated - (int) round($reading->getIndoorTemperature() * 10);

                $all[] = $error;
                $bySlot[$reading->getSlot()->value][] = $error;
                $byPlace[$reading->getPlace()->getName()][] = $error;
            }
        }

        ksort($byPlace);

        return new AccuracyReport(
            [] === $all ? null : AccuracyStats::fromErrors($all, RecommendationEngine::TOLERANCE_TENTHS),
            array_map(static fn (array $e): AccuracyStats => AccuracyStats::fromErrors($e, RecommendationEngine::TOLERANCE_TENTHS), $bySlot),
            array_map(static fn (array $e): AccuracyStats => AccuracyStats::fromErrors($e, RecommendationEngine::TOLERANCE_TENTHS), $byPlace),
            \count($sessions),
            $skipped,
        );
    }
}
