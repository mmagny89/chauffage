<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaCalculator;
use App\Calculation\DeltaModelFitter;
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
        private DeltaModelFitter $fitter,
        private RecommendationEngine $engine,
    ) {
    }

    /**
     * @return list<DayRecommendation>
     *
     * @throws \App\Forecast\HouseholdNotLocatedException
     * @throws \App\Forecast\ForecastUnavailableException
     */
    public function forHousehold(Household $household, ?Analysis $analysis = null): array
    {
        $targets = [];
        foreach (Weekday::cases() as $day) {
            foreach (DaySlot::cases() as $slot) {
                $targets[$day->value][$slot->value] = $household->targetFor($day, $slot)->getTemperature();
            }
        }

        return $this->engine->recommend(
            $this->forecasts->forHousehold($household),
            ($analysis ?? $this->analyze($household))->models,
            $targets,
        );
    }

    /**
     * Analyse les relevés du foyer : une seule lecture en base pour les moyennes et les modèles.
     */
    public function analyze(Household $household): Analysis
    {
        $readings = $this->readings->findByHousehold($household);

        return new Analysis($this->calculator->calculate($readings), $this->fitter->fit($readings));
    }
}
