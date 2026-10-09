<?php

declare(strict_types=1);

namespace App\Forecast;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prévisions horaires d'Open-Meteo (https://open-meteo.com/en/docs), données CC BY 4.0.
 *
 * Mises en cache une heure par position (arrondie à ~1 km) : le modèle ne se met pas
 * à jour plus souvent, et deux foyers voisins partagent la même réponse.
 */
final readonly class OpenMeteoForecastProvider implements HourlyTemperatureProviderInterface
{
    private const CACHE_TTL = 3600;

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
        $key = 'forecast_'.hash('sha256', \sprintf('%.2f|%.2f|%s|%d', $latitude, $longitude, $timezone, $days));

        /** @var list<array{string, float}> $rows */
        $rows = $this->cache->get($key, function (ItemInterface $item) use ($latitude, $longitude, $timezone, $days): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->request($latitude, $longitude, $timezone, $days);
        });

        $zone = new \DateTimeZone($timezone);

        return array_map(
            static fn (array $row): HourlyTemperature => new HourlyTemperature(new \DateTimeImmutable($row[0], $zone), $row[1]),
            $rows,
        );
    }

    /**
     * @return list<array{string, float}> heure locale « Y-m-d\TH:i » et température
     */
    private function request(float $latitude, float $longitude, string $timezone, int $days): array
    {
        try {
            $payload = $this->client->request('GET', 'v1/forecast', [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'hourly' => 'temperature_2m',
                    'forecast_days' => $days,
                    'timezone' => $timezone,
                ],
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new ForecastUnavailableException('Le service de prévisions est indisponible.', 0, $exception);
        }

        $times = $payload['hourly']['time'] ?? null;
        $temperatures = $payload['hourly']['temperature_2m'] ?? null;
        if (!\is_array($times) || !\is_array($temperatures) || \count($times) !== \count($temperatures)) {
            throw new ForecastUnavailableException('Réponse de prévisions inattendue.');
        }

        $rows = [];
        foreach ($times as $index => $time) {
            $temperature = $temperatures[$index];
            // L'API peut laisser une heure sans valeur : on l'ignore plutôt que de la compter pour zéro.
            if (!\is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $time) || !\is_int($temperature) && !\is_float($temperature)) {
                continue;
            }
            $rows[] = [$time, (float) $temperature];
        }

        if ([] === $rows) {
            throw new ForecastUnavailableException('Aucune prévision exploitable dans la réponse.');
        }

        return $rows;
    }
}
