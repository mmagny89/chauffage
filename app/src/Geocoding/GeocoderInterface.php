<?php

declare(strict_types=1);

namespace App\Geocoding;

interface GeocoderInterface
{
    /**
     * @return list<GeocodedPlace> vide si rien ne correspond
     *
     * @throws GeocodingUnavailableException si le service ne répond pas ou répond de travers
     */
    public function search(string $query): array;
}
