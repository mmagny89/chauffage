<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Forecast\ForecastUnavailableException;
use App\Shutter\DailySun;
use App\Shutter\DailySunProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * Double de l'ensoleillement : lever 7 h 30, coucher 18 h, moyenne de 5 °C. Le premier jour est ensoleillé
 * (8 h de soleil), les suivants couverts (1 h). Une latitude supérieure à 80 simule une panne.
 */
final readonly class FakeDailySunProvider implements DailySunProviderInterface
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
        $result = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $start->modify(\sprintf('+%d days', $i));
            $result[] = new DailySun($day, $day->setTime(7, 30), $day->setTime(18, 0), 0 === $i ? 8 * 3600 : 3600, 50);
        }

        return $result;
    }
}
