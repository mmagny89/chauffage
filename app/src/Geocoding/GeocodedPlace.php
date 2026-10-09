<?php

declare(strict_types=1);

namespace App\Geocoding;

/**
 * Une localité renvoyée par la recherche de ville.
 */
final readonly class GeocodedPlace
{
    public function __construct(
        public string $name,
        public ?string $region,
        public ?string $country,
        public float $latitude,
        public float $longitude,
        public string $timezone,
    ) {
    }

    /**
     * Libellé pour distinguer deux villes du même nom, ex. « Lyon (Rhône, France) ».
     */
    public function label(): string
    {
        $context = implode(', ', array_filter([$this->region, $this->country]));

        return '' === $context ? $this->name : \sprintf('%s (%s)', $this->name, $context);
    }
}
