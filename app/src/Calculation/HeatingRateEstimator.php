<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\HeatingStart;

/**
 * Estime la vitesse de chauffe d'après les allumages dont la consigne a été notée atteinte : degrés gagnés
 * (consigne − température de la pièce à l'allumage) divisés par la durée, moyennés. Calcul pur.
 *
 * Hypothèses, à réviser avec des données réelles : la montée est prise linéaire (en vrai elle ralentit à
 * l'approche de la consigne) et indépendante de la température extérieure. Un allumage qui gagne moins de
 * MIN_RISE degrés ou dure moins de MIN_MINUTES est ignoré : trop court pour dire quelque chose. Il faut
 * MIN_SAMPLES allumages avant de donner une vitesse.
 */
final class HeatingRateEstimator
{
    public const MIN_SAMPLES = 2;
    public const MIN_RISE = 0.5;
    public const MIN_MINUTES = 10;

    /**
     * @param iterable<HeatingStart> $starts
     */
    public function estimate(iterable $starts): HeatingRates
    {
        /** @var list<float> $all */
        $all = [];
        /** @var array<string, list<float>> $byPlace */
        $byPlace = [];
        $completed = 0;

        foreach ($starts as $start) {
            $minutes = $start->getWarmUpMinutes();
            if (null === $minutes) {
                continue;
            }
            ++$completed;
            if ($minutes < self::MIN_MINUTES || $start->getGap() < self::MIN_RISE) {
                continue;
            }
            $rate = $start->getGap() / ($minutes / 60);
            $all[] = $rate;
            $byPlace[mb_strtolower($start->getPlace()->getName())][] = $rate;
        }

        return new HeatingRates(
            self::mean($all),
            array_filter(array_map(self::mean(...), $byPlace)),
            $completed,
        );
    }

    /**
     * @param list<float> $rates
     */
    private static function mean(array $rates): ?HeatingRate
    {
        if (\count($rates) < self::MIN_SAMPLES) {
            return null;
        }

        return new HeatingRate(round(array_sum($rates) / \count($rates), 2), \count($rates));
    }
}
