<?php

declare(strict_types=1);

namespace App\Geocoding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche de ville par l'API de géocodage d'Open-Meteo (données GeoNames).
 *
 * Gratuite sans clé pour un usage non commercial ; les résultats sont mis en cache
 * un jour par requête normalisée, pour ne pas rappeler l'API à chaque affichage.
 */
final readonly class OpenMeteoGeocoder implements GeocoderInterface
{
    private const MAX_RESULTS = 6;
    private const CACHE_TTL = 86400;

    public function __construct(
        #[Autowire(service: 'geocoding.client')]
        private HttpClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    public function search(string $query): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->cache->get(
            'geocoding_'.hash('sha256', mb_strtolower($query)),
            function (ItemInterface $item) use ($query): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetch($query);
            },
        );
    }

    /**
     * @return list<GeocodedPlace>
     */
    private function fetch(string $query): array
    {
        try {
            $payload = $this->client->request('GET', 'v1/search', [
                'query' => ['name' => $query, 'count' => self::MAX_RESULTS, 'language' => 'fr', 'format' => 'json'],
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new GeocodingUnavailableException('Le service de recherche de ville est indisponible.', 0, $exception);
        }

        // Sans correspondance, l'API omet la clé « results ».
        $places = [];
        foreach ($payload['results'] ?? [] as $result) {
            if (!\is_array($result) || !isset($result['name'], $result['latitude'], $result['longitude'], $result['timezone'])) {
                continue;
            }

            $places[] = new GeocodedPlace(
                (string) $result['name'],
                self::optionalString($result['admin2'] ?? $result['admin1'] ?? null),
                self::optionalString($result['country'] ?? null),
                (float) $result['latitude'],
                (float) $result['longitude'],
                (string) $result['timezone'],
            );
        }

        return $places;
    }

    private static function optionalString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
