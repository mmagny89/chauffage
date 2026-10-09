<?php

declare(strict_types=1);

namespace App\Forecast;

interface HourlyTemperatureProviderInterface
{
    /**
     * Prévisions horaires depuis minuit (heure locale) du jour courant.
     *
     * @param string $timezone nom IANA : les heures renvoyées sont celles de ce fuseau
     *
     * @return list<HourlyTemperature>
     *
     * @throws ForecastUnavailableException
     */
    public function fetch(float $latitude, float $longitude, string $timezone, int $days): array;
}
