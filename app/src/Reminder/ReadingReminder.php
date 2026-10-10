<?php

declare(strict_types=1);

namespace App\Reminder;

use App\Calculation\DeltaModel;
use App\Enum\DaySlot;
use App\Forecast\DayForecast;
use App\Service\Calibration;

/**
 * Suggère un relevé quand il apporterait quelque chose. Par ordre de priorité : une température annoncée
 * aujourd'hui ou demain hors de la plage déjà relevée (le modèle extrapole) ; un dernier relevé ancien ;
 * un calibrage inachevé sans relevé aujourd'hui. Calcul pur.
 *
 * Le délai de péremption est une hypothèse : une maison change peu en une semaine, mais un relevé
 * récent rassure sur la saison.
 */
final class ReadingReminder
{
    /** Au-delà de ce nombre de jours sans relevé, on le rappelle. */
    public const STALE_AFTER_DAYS = 7;

    private const DAYS = 2;

    /**
     * @param \DateTimeImmutable $now      heure murale locale du foyer
     * @param list<DayForecast>  $forecast vide si la prévision est indisponible
     */
    public function remind(int $daysDone, ?\DateTimeImmutable $lastReadingAt, \DateTimeImmutable $now, ?DeltaModel $overall, array $forecast): ?Reminder
    {
        // Sans aucun relevé, le tableau de bord invite déjà à commencer : pas de rappel en plus.
        if (null === $lastReadingAt || null === $overall) {
            return null;
        }

        $outOfRange = $this->outOfRange($overall, $forecast, $now);
        if (null !== $outOfRange) {
            return $outOfRange;
        }

        $daysSince = (int) $lastReadingAt->setTime(0, 0)->diff($now->setTime(0, 0))->days;
        if ($daysSince >= self::STALE_AFTER_DAYS) {
            return new Reminder(ReminderReason::Stale, daysSince: $daysSince);
        }

        if ($daysDone < Calibration::DAYS_REQUIRED && 0 !== $daysSince) {
            return new Reminder(ReminderReason::Calibration, daysMissing: Calibration::DAYS_REQUIRED - $daysDone);
        }

        return null;
    }

    /**
     * @param list<DayForecast> $forecast
     */
    private function outOfRange(DeltaModel $overall, array $forecast, \DateTimeImmutable $now): ?Reminder
    {
        $worst = null;
        foreach (\array_slice($forecast, 0, self::DAYS) as $day) {
            foreach (DaySlot::chronological() as $slot) {
                $end = $day->date->setTime(0, 0)->modify(\sprintf('+%d hours', $slot->endsAtHour()));
                $average = $day->forSlot($slot)->average;
                if ($end <= $now || null === $average || $overall->covers($average)) {
                    continue;
                }
                $distance = max($overall->outdoorMin - DeltaModel::EXTRAPOLATION_MARGIN - $average, $average - $overall->outdoorMax - DeltaModel::EXTRAPOLATION_MARGIN);
                if (null === $worst || $distance > $worst[0]) {
                    $worst = [$distance, $average, $day->date, $slot];
                }
            }
        }

        return null === $worst ? null : new Reminder(
            ReminderReason::OutOfRange,
            forecast: $worst[1],
            date: $worst[2],
            slot: $worst[3],
            measuredMin: $overall->outdoorMin,
            measuredMax: $overall->outdoorMax,
        );
    }
}
