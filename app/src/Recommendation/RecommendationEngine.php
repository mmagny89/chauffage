<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaReport;
use App\Calculation\SlotDelta;
use App\Enum\DaySlot;
use App\Forecast\DayForecast;

/**
 * Décide, pour chaque jour et créneau, s'il faut chauffer ou si on peut couper.
 *
 * Règle : température intérieure estimée = température extérieure prévue + écart moyen
 * du foyer sur ce créneau (à défaut de relevé sur ce créneau, écart moyen tous créneaux
 * confondus, signalé). Si l'estimation est strictement sous la température visée, on
 * chauffe, à cette température ; sinon on coupe. Calcul pur, en dixièmes de degré entiers.
 */
final class RecommendationEngine
{
    /**
     * @param list<DayForecast>       $days
     * @param array<string, float>    $targets température visée par créneau, indexée par la valeur de DaySlot
     *
     * @return list<DayRecommendation>
     */
    public function recommend(array $days, DeltaReport $deltas, array $targets): array
    {
        return array_map(function (DayForecast $day) use ($deltas, $targets): DayRecommendation {
            $slots = [];
            foreach (DaySlot::cases() as $slot) {
                $slots[$slot->value] = $this->forSlot($day, $slot, $deltas, $targets[$slot->value]);
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

    private function forSlot(DayForecast $day, DaySlot $slot, DeltaReport $deltas, float $target): SlotRecommendation
    {
        $forecast = $day->forSlot($slot);
        if (!$forecast->hasData()) {
            return SlotRecommendation::unknown($slot, $target, null, $forecast->isComplete());
        }
        \assert(null !== $forecast->average);

        $fallback = false;
        $delta = $deltas->household->forSlot($slot);
        if (0 === $delta->count) {
            $delta = $deltas->household->overall;
            $fallback = true;
        }
        if (null === $delta->average) {
            return SlotRecommendation::unknown($slot, $target, $forecast->average, $forecast->isComplete());
        }

        $estimatedTenths = (int) round($forecast->average * 10) + (int) round($delta->average * 10);
        $targetTenths = (int) round($target * 10);

        return new SlotRecommendation(
            $slot,
            $estimatedTenths < $targetTenths ? HeatingAction::Heat : HeatingAction::Cut,
            $target,
            $forecast->average,
            $delta->average,
            $estimatedTenths / 10,
            $delta->count,
            $fallback,
            $forecast->isComplete(),
        );
    }
}
