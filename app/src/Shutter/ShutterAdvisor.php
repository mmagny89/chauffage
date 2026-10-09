<?php

declare(strict_types=1);

namespace App\Shutter;

/**
 * Indication d'hiver pour les volets, valable pour tout le foyer : fermer à la tombée de la nuit, ouvrir
 * quand il y a du soleil à récupérer. Calcul pur, sans orientation ni relevé.
 *
 * Les deux seuils sont des hypothèses, pas des mesures : à réviser avec l'usage.
 */
final class ShutterAdvisor
{
    /** Au-dessus de cette température moyenne (dixièmes de degré), le chauffage ne dépend pas des volets. */
    public const COLD_BELOW_TENTHS = 150;

    /** Une journée est ensoleillée quand le soleil brille au moins la moitié du temps où il est levé. */
    public const SUNNY_SHARE = 0.5;

    public function advise(DailySun $day): ShutterAdvice
    {
        $daylight = $day->sunset->getTimestamp() - $day->sunrise->getTimestamp();

        $plan = match (true) {
            $day->meanTenths >= self::COLD_BELOW_TENTHS => ShutterPlan::Mild,
            $daylight > 0 && $day->sunshineSeconds >= $daylight * self::SUNNY_SHARE => ShutterPlan::OpenForSun,
            default => ShutterPlan::StayClosed,
        };

        return new ShutterAdvice($day->date, $plan, $day->sunrise, $day->sunset, intdiv($day->sunshineSeconds, 60));
    }
}
