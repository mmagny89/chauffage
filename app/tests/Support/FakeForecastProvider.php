<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Forecast\ForecastUnavailableException;
use App\Forecast\HourlyTemperature;
use App\Forecast\HourlyTemperatureProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * Double des prévisions : la température d'une heure vaut cette heure (0 à 23 °C), tous
 * les jours, à partir de minuit du jour courant. Une latitude supérieure à 80 simule une panne.
 *
 * Moyennes qui en découlent : nuit 7,5 (22-23 h puis 0-5 h), matin 8,5, après-midi 14,5, soirée 19,5.
 */
final readonly class FakeForecastProvider implements HourlyTemperatureProviderInterface
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function fetch(float $latitude, float $longitude, string $timezone, int $days): array
    {
        if ($latitude > 80) {
            throw new ForecastUnavailableException('Panne simulée.');
        }

        $start = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d'));
        $hours = [];
        for ($i = 0; $i < $days * 24; ++$i) {
            $at = $start->modify(\sprintf('+%d hours', $i));
            $hours[] = new HourlyTemperature($at, (float) (int) $at->format('G'));
        }

        return $hours;
    }
}
