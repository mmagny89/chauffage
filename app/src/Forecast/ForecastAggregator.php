<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enum\DaySlot;

/**
 * Regroupe des températures horaires en créneaux de la journée, jour par jour.
 *
 * La nuit d'un jour D court de 22 h le jour D à 6 h le lendemain (la « nuit du
 * jeudi au vendredi ») : les heures de 0 h à 6 h appartiennent donc à la nuit de la
 * veille. Calcul pur, sans dépendance.
 */
final class ForecastAggregator
{
    private const HOURS = [
        'night' => 8,
        'morning' => 6,
        'afternoon' => 6,
        'evening' => 4,
    ];

    /**
     * @param iterable<HourlyTemperature> $hours
     * @param \DateTimeImmutable          $firstDay premier jour à restituer (seule la date compte)
     * @param positive-int                $days
     *
     * @return list<DayForecast>
     */
    public function aggregate(iterable $hours, \DateTimeImmutable $firstDay, int $days): array
    {
        /** @var array<string, array<string, list<int>>> $buckets jour => créneau => dixièmes de degré */
        $buckets = [];
        foreach ($hours as $hour) {
            $date = $hour->at->setTime(0, 0);
            $slot = DaySlot::fromDateTime($hour->at);
            if ((int) $hour->at->format('G') < 6) {
                $date = $date->modify('-1 day');
            }
            $buckets[$date->format('Y-m-d')][$slot->value][] = (int) round($hour->celsius * 10);
        }

        $first = $firstDay->setTime(0, 0);
        $result = [];
        for ($offset = 0; $offset < $days; ++$offset) {
            $date = $first->modify(\sprintf('+%d days', $offset));
            $slots = [];
            foreach (DaySlot::cases() as $slot) {
                $slots[$slot->value] = self::summarize($slot, $buckets[$date->format('Y-m-d')][$slot->value] ?? []);
            }
            $result[] = new DayForecast($date, $slots);
        }

        return $result;
    }

    /**
     * @param list<int> $tenths
     */
    private static function summarize(DaySlot $slot, array $tenths): SlotForecast
    {
        $expected = self::HOURS[$slot->value];
        if ([] === $tenths) {
            return new SlotForecast($slot, null, null, null, 0, $expected);
        }

        return new SlotForecast(
            $slot,
            round(array_sum($tenths) / \count($tenths)) / 10,
            min($tenths) / 10,
            max($tenths) / 10,
            \count($tenths),
            $expected,
        );
    }
}
