<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Geocoding\GeocodedPlace;
use App\Geocoding\GeocoderInterface;
use App\Geocoding\GeocodingUnavailableException;

/**
 * Double de la recherche de ville : « Lyon » a deux résultats, « Panne » simule
 * une indisponibilité, tout le reste ne trouve rien.
 */
final class FakeGeocoder implements GeocoderInterface
{
    public function search(string $query): array
    {
        return match (mb_strtolower(trim($query))) {
            'lyon' => [
                new GeocodedPlace('Lyon', 'Rhône', 'France', 45.74906, 4.84789, 'Europe/Paris'),
                new GeocodedPlace('Lyon', 'Mississippi', 'États-Unis', 34.21789, -90.54204, 'America/Chicago'),
            ],
            'panne' => throw new GeocodingUnavailableException('Service indisponible.'),
            default => [],
        };
    }
}
