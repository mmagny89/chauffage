<?php

declare(strict_types=1);

namespace App\Shutter;

use App\Forecast\ForecastUnavailableException;

interface DailySunProviderInterface
{
    /**
     * Lever, coucher, ensoleillement et température moyenne des prochains jours, à partir d'aujourd'hui.
     *
     * @param string $timezone nom IANA : les heures renvoyées sont celles de ce fuseau
     *
     * @return list<DailySun>
     *
     * @throws ForecastUnavailableException
     */
    public function fetch(float $latitude, float $longitude, string $timezone, int $days): array;
}
