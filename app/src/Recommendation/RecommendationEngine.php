<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaModels;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Forecast\DayForecast;

/**
 * Décide, pour chaque jour et créneau, s'il faut chauffer ou si on peut couper.
 *
 * Règle : la température visée dépend du jour de la semaine et du créneau ; température
 * intérieure estimée = température extérieure prévue + écart du foyer sur ce créneau, calculé
 * par son modèle à cette température extérieure (régression, ou écart moyen faute de données ;
 * à défaut de relevé sur le créneau, modèle de tous les créneaux, signalé). Une prévision hors
 * de la plage des températures mesurées est signalée. Si l'estimation est strictement sous
 * la température visée, on chauffe, à cette température ; sinon on coupe. Calcul pur, en
 * dixièmes de degré entiers.
 */
final class RecommendationEngine
{
    /**
     * @param list<DayForecast>                $days
     * @param array<int, array<string, float>> $targets température visée, indexée par le numéro du jour de la semaine (1 = lundi)
     *                                                  puis par la valeur de DaySlot
     *
     * @return list<DayRecommendation>
     */
    public function recommend(array $days, DeltaModels $models, array $targets): array
    {
        return array_map(function (DayForecast $day) use ($models, $targets): DayRecommendation {
            $dayTargets = $targets[Weekday::fromDate($day->date)->value];
            $slots = [];
            foreach (DaySlot::cases() as $slot) {
                $slots[$slot->value] = $this->forSlot($day, $slot, $models, $dayTargets[$slot->value]);
            }

            return new DayRecommendation($day->date, $slots);
        }, $days);
    }

    /**
     * Les créneaux qui restent à vivre à partir de maintenant, dans l'ordre : celui en
     * cours d'abord, puis les suivants.
     *
     * @param list<DayRecommendation> $days
     * @param \DateTimeImmutable      $now  heure murale locale du foyer
     *
     * @return list<UpcomingSlot>
     */
    public function upcoming(array $days, \DateTimeImmutable $now, int $count): array
    {
        $upcoming = [];
        foreach ($days as $day) {
            foreach (DaySlot::chronological() as $slot) {
                $end = $day->date->setTime(0, 0)->modify(\sprintf('+%d hours', $slot->endsAtHour()));
                if ($end <= $now) {
                    continue;
                }
                $upcoming[] = new UpcomingSlot($day->date, $day->forSlot($slot));
                if (\count($upcoming) === $count) {
                    return $upcoming;
                }
            }
        }

        return $upcoming;
    }

    private function forSlot(DayForecast $day, DaySlot $slot, DeltaModels $models, float $target): SlotRecommendation
    {
        $forecast = $day->forSlot($slot);
        if (!$forecast->hasData()) {
            return SlotRecommendation::unknown($slot, $target, null, $forecast->isComplete());
        }
        \assert(null !== $forecast->average);

        $fallback = false;
        $model = $models->forSlot($slot);
        if (null === $model) {
            $model = $models->overall;
            $fallback = true;
        }
        if (null === $model) {
            return SlotRecommendation::unknown($slot, $target, $forecast->average, $forecast->isComplete());
        }

        $deltaTenths = (int) round($model->deltaAt($forecast->average) * 10);
        $estimatedTenths = (int) round($forecast->average * 10) + $deltaTenths;
        $targetTenths = (int) round($target * 10);

        return new SlotRecommendation(
            $slot,
            $estimatedTenths < $targetTenths ? HeatingAction::Heat : HeatingAction::Cut,
            $target,
            $forecast->average,
            $deltaTenths / 10,
            $estimatedTenths / 10,
            $model->readings,
            $fallback,
            $forecast->isComplete(),
            $model->kind,
            !$model->covers($forecast->average),
        );
    }
}
