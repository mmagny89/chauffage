<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Entity\Household;
use Psr\Clock\ClockInterface;

/**
 * Prévisions de température extérieure d'un foyer, sur quinze jours, par créneau.
 */
final readonly class ForecastService
{
    /** Jours affichés. */
    public const DAYS = 15;

    /** Jours demandés : un de plus, pour que la nuit du dernier jour affiché soit complète. */
    private const FETCHED_DAYS = self::DAYS + 1;

    public function __construct(
        private HourlyTemperatureProviderInterface $provider,
        private ForecastAggregator $aggregator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<DayForecast>
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

        $timezone = $household->getTimezone();
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d'));

        return $this->aggregator->aggregate(
            $this->provider->fetch($latitude, $longitude, $timezone, self::FETCHED_DAYS),
            $today,
            self::DAYS,
        );
    }
}
