<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaModels;
use App\Calculation\HeatingDays;
use App\Calculation\HeatingRates;
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
 * de la plage des températures mesurées est signalée. Si l'estimation est sous la température
 * visée de plus de 1 °C (TOLERANCE_TENTHS), on chauffe, à cette température ; sinon on coupe. Calcul pur, en
 * dixièmes de degré entiers.
 *
 *
 * Dans la liste des créneaux à venir, un créneau où il faut chauffer reçoit la durée estimée de la montée
 * en température (vitesse mesurée sur les allumages dont la consigne a été notée atteinte), sauf si le
 * chauffage est déjà allumé ; un créneau d'aujourd'hui où le chauffage a déjà été allumé est
 * marqué comme tel : l'affichage dit « Chauffer » sans température (HeatingDays).
 */
final class RecommendationEngine
{
    /** Écart toléré entre la température visée et l'intérieur estimé, en dixièmes de degré (1 °C). */
    public const TOLERANCE_TENTHS = 10;

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
     * @param \DateTimeImmutable      $now       heure murale locale du foyer
     * @param string|null             $placeName nom de la pièce des créneaux ; null pour le foyer entier
     *
     * @return list<UpcomingSlot>
     */
    public function upcoming(array $days, \DateTimeImmutable $now, int $count, ?HeatingDays $heating = null, ?string $placeName = null, ?HeatingRates $rates = null): array
    {
        $upcoming = [];
        foreach ($days as $day) {
            foreach (DaySlot::chronological() as $slot) {
                $end = $day->date->setTime(0, 0)->modify(\sprintf('+%d hours', $slot->endsAtHour()));
                if ($end <= $now) {
                    continue;
                }
                $recommendation = $day->forSlot($slot);
                $heatedToday = null !== $heating && $day->date->format('Y-m-d') === $now->format('Y-m-d') && $heating->has($day->date->format('Y-m-d'), $placeName);
                if ($heatedToday) {
                    $recommendation = $recommendation->withHeatingOn();
                } elseif (HeatingAction::Heat === $recommendation->action && null !== $recommendation->estimatedIndoor) {
                    $minutes = $rates?->forPlace($placeName)?->minutesToRise($recommendation->target - $recommendation->estimatedIndoor);
                    $recommendation = null === $minutes ? $recommendation : $recommendation->withWarmUp($minutes);
                }
                $upcoming[] = new UpcomingSlot($day->date, $recommendation);
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
        $action = $targetTenths - $estimatedTenths > self::TOLERANCE_TENTHS ? HeatingAction::Heat : HeatingAction::Cut;

        return new SlotRecommendation(
            $slot,
            $action,
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
