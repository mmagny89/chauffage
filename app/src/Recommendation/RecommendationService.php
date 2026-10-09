<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaCalculator;
use App\Calculation\DeltaReport;
use App\Entity\Household;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Forecast\ForecastService;
use App\Repository\ReadingRepository;

/**
 * Assemble les prévisions, les écarts mesurés et les températures visées d'un foyer.
 */
final readonly class RecommendationService
{
    public function __construct(
        private ForecastService $forecasts,
        private ReadingRepository $readings,
        private DeltaCalculator $calculator,
        private RecommendationEngine $engine,
    ) {
    }

    /**
     * @return list<DayRecommendation>
     *
     * @throws \App\Forecast\HouseholdNotLocatedException
     * @throws \App\Forecast\ForecastUnavailableException
     */
    public function forHousehold(Household $household, ?DeltaReport $deltas = null): array
    {
        $targets = [];
        foreach (Weekday::cases() as $day) {
            foreach (DaySlot::cases() as $slot) {
                $targets[$day->value][$slot->value] = $household->targetFor($day, $slot)->getTemperature();
            }
        }

        return $this->engine->recommend(
            $this->forecasts->forHousehold($household),
            $deltas ?? $this->deltas($household),
            $targets,
        );
    }

    public function deltas(Household $household): DeltaReport
    {
        return $this->calculator->calculate($this->readings->findByHousehold($household));
    }
}
