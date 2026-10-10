<?php

declare(strict_types=1);

namespace App\Alert;

use App\Enum\DaySlot;
use App\Forecast\DayForecast;

/**
 * Prévient d'un gel ou d'un grand froid dans les prochaines 48 heures (aujourd'hui et demain, créneaux
 * déjà terminés exclus). Calcul pur, en dixièmes de degré entiers.
 *
 * Les deux seuils sont des conventions, pas des mesures de ce que supporte un logement : sous 0 °C on
 * pense aux canalisations et aux plantes, à -5 °C ou moins au grand froid.
 */
final class FrostAdvisor
{
    /** Gel : strictement sous cette température (dixièmes de degré). */
    public const FROST_BELOW_TENTHS = 0;

    /** Grand froid : à cette température ou en dessous (dixièmes de degré). */
    public const SEVERE_AT_OR_BELOW_TENTHS = -50;

    private const DAYS = 2;

    /**
     * @param list<DayForecast>  $days prévisions, le jour courant en premier
     * @param \DateTimeImmutable $now  heure murale locale du foyer
     */
    public function advise(array $days, \DateTimeImmutable $now): ?FrostAlert
    {
        $lowest = null;
        foreach (\array_slice($days, 0, self::DAYS) as $day) {
            foreach (DaySlot::chronological() as $slot) {
                $end = $day->date->setTime(0, 0)->modify(\sprintf('+%d hours', $slot->endsAtHour()));
                $min = $day->forSlot($slot)->min;
                if ($end <= $now || null === $min) {
                    continue;
                }
                $tenths = (int) round($min * 10);
                if (null === $lowest || $tenths < $lowest[0]) {
                    $lowest = [$tenths, $day->date, $slot];
                }
            }
        }

        if (null === $lowest || $lowest[0] >= self::FROST_BELOW_TENTHS) {
            return null;
        }

        return new FrostAlert(
            $lowest[0] <= self::SEVERE_AT_OR_BELOW_TENTHS ? FrostLevel::Severe : FrostLevel::Frost,
            $lowest[0] / 10,
            $lowest[1],
            $lowest[2],
        );
    }
}
