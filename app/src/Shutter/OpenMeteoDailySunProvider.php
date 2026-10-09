<?php

declare(strict_types=1);

namespace App\Shutter;

use App\Forecast\ForecastUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Données quotidiennes d'Open-Meteo (https://open-meteo.com/en/docs), CC BY 4.0, mises en cache une heure
 * par position arrondie, comme les prévisions horaires.
 */
final readonly class OpenMeteoDailySunProvider implements DailySunProviderInterface
{
    private const CACHE_TTL = 3600;
    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';

    public function __construct(
        #[Autowire(service: 'forecast.client')]
        private HttpClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    public function fetch(float $latitude, float $longitude, string $timezone, int $days): array
    {
        $latitude = round($latitude, 2);
        $longitude = round($longitude, 2);
        $key = 'sun_'.hash('sha256', \sprintf('%.2f|%.2f|%s|%d', $latitude, $longitude, $timezone, $days));

        /** @var list<array{string, string, string, int, int}> $rows */
        $rows = $this->cache->get($key, function (ItemInterface $item) use ($latitude, $longitude, $timezone, $days): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->request($latitude, $longitude, $timezone, $days);
        });

        $zone = new \DateTimeZone($timezone);

        return array_map(
            static fn (array $row): DailySun => new DailySun(
                new \DateTimeImmutable($row[0], $zone),
                new \DateTimeImmutable($row[1], $zone),
                new \DateTimeImmutable($row[2], $zone),
                $row[3],
                $row[4],
            ),
            $rows,
        );
    }

    /**
     * @return list<array{string, string, string, int, int}> jour, lever, coucher, ensoleillement (s), moyenne (dixièmes)
     */
    private function request(float $latitude, float $longitude, string $timezone, int $days): array
    {
        try {
            $payload = $this->client->request('GET', 'v1/forecast', [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'daily' => 'sunrise,sunset,sunshine_duration,temperature_2m_mean',
                    'forecast_days' => $days,
                    'timezone' => $timezone,
                ],
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new ForecastUnavailableException('Le service de prévisions est indisponible.', 0, $exception);
        }

        $daily = $payload['daily'] ?? null;
        if (!\is_array($daily)) {
            throw new ForecastUnavailableException('Réponse de prévisions inattendue.');
        }

        $rows = [];
        foreach ((array) ($daily['time'] ?? []) as $index => $day) {
            $sunrise = $daily['sunrise'][$index] ?? null;
            $sunset = $daily['sunset'][$index] ?? null;
            $sunshine = $daily['sunshine_duration'][$index] ?? null;
            $mean = $daily['temperature_2m_mean'][$index] ?? null;
            // Un jour incomplet (nuit polaire, valeur absente) est ignoré plutôt que compté pour zéro.
            if (!\is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
                || !\is_string($sunrise) || !preg_match(self::TIME, $sunrise)
                || !\is_string($sunset) || !preg_match(self::TIME, $sunset)
                || !is_numeric($sunshine) || !is_numeric($mean)) {
                continue;
            }
            $rows[] = [$day, $sunrise, $sunset, (int) round((float) $sunshine), (int) round((float) $mean * 10)];
        }

        if ([] === $rows) {
            throw new ForecastUnavailableException('Aucune donnée d’ensoleillement exploitable dans la réponse.');
        }

        return $rows;
    }
}
