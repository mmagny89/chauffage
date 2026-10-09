<?php

declare(strict_types=1);

namespace App\Shutter;

use App\Entity\Household;
use App\Forecast\ForecastUnavailableException;
use App\Forecast\HouseholdNotLocatedException;

/**
 * Indications d'aujourd'hui et de demain pour les volets du foyer.
 */
final readonly class ShutterService
{
    private const DAYS = 2;

    public function __construct(
        private DailySunProviderInterface $provider,
        private ShutterAdvisor $advisor,
    ) {
    }

    /**
     * @return list<ShutterAdvice>
     *
     * @throws HouseholdNotLocatedException
     * @throws ForecastUnavailableException
     */
    public function forHousehold(Household $household): array
    {
        $latitude = $household->getLatitude();
        $longitude = $household->getLongitude();
        if (null === $latitude || null === $longitude) {
            throw new HouseholdNotLocatedException('Le foyer n’a pas de ville renseignée.');
        }

        return array_map(
            $this->advisor->advise(...),
            $this->provider->fetch($latitude, $longitude, $household->getTimezone(), self::DAYS),
        );
    }
}
