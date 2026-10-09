<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\Reading;
use App\Enum\DaySlot;

/**
 * Détermine l'écart entre l'intérieur et l'extérieur et en établit la moyenne, par
 * lieu et par créneau de la journée.
 *
 * Calcul pur : aucune dépendance, aucun accès à la base. Les écarts sont sommés en
 * dixièmes de degré entiers, puis la moyenne est arrondie au dixième (demi vers
 * l'éloigné de zéro), pour qu'un résultat ne dépende ni de l'ordre des relevés ni
 * des erreurs d'arrondi des flottants.
 */
final class DeltaCalculator
{
    /**
     * @param iterable<Reading> $readings
     */
    public function calculate(iterable $readings): DeltaReport
    {
        /** @var array<string, array{name: string, sums: array<string, array{int, int}>}> $places */
        $places = [];
        /** @var array<string, array{int, int}> $household */
        $household = [];

        foreach ($readings as $reading) {
            $name = $reading->getPlace()->getName();
            $key = mb_strtolower($name);
            $slot = $reading->getSlot()->value;
            $tenths = (int) round($reading->getDelta() * 10);

            $places[$key] ??= ['name' => $name, 'sums' => []];
            $places[$key]['sums'][$slot] = self::add($places[$key]['sums'][$slot] ?? [0, 0], $tenths);
            $household[$slot] = self::add($household[$slot] ?? [0, 0], $tenths);
        }

        $details = array_map(
            static fn (array $place): PlaceDeltas => self::summarize($place['name'], $place['sums']),
            $places,
        );
        $details = array_values($details);
        self::sortByName($details);

        return new DeltaReport($details, self::summarize('Tous les lieux', $household));
    }

    /**
     * @param array{int, int} $sum
     *
     * @return array{int, int}
     */
    private static function add(array $sum, int $tenths): array
    {
        return [$sum[0] + $tenths, $sum[1] + 1];
    }

    /**
     * @param array<string, array{int, int}> $sums somme (dixièmes) et nombre de relevés par créneau
     */
    private static function summarize(string $name, array $sums): PlaceDeltas
    {
        $bySlot = [];
        $totalTenths = 0;
        $totalCount = 0;

        foreach (DaySlot::cases() as $slot) {
            [$tenths, $count] = $sums[$slot->value] ?? [0, 0];
            $bySlot[$slot->value] = SlotDelta::fromTenths($tenths, $count);
            $totalTenths += $tenths;
            $totalCount += $count;
        }

        return new PlaceDeltas($name, SlotDelta::fromTenths($totalTenths, $totalCount), $bySlot);
    }

    /**
     * @param list<PlaceDeltas> $places
     */
    private static function sortByName(array &$places): void
    {
        $collator = new \Collator('fr_FR');
        usort($places, static fn (PlaceDeltas $a, PlaceDeltas $b): int => $collator->compare($a->placeName, $b->placeName));
    }
}
