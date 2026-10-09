<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaCalculator;
use App\Calculation\DeltaModelFitter;
use App\Calculation\HeatingDays;
use App\Entity\Household;
use App\Entity\Place;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Forecast\ForecastService;
use App\Repository\HeatingStartRepository;
use App\Repository\ReadingRepository;

/**
 * Assemble les prévisions, les écarts mesurés et les températures visées d'un foyer.
 */
final readonly class RecommendationService
{
    public function __construct(
        private ForecastService $forecasts,
        private ReadingRepository $readings,
        private HeatingStartRepository $heatingStarts,
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
        return $this->engine->recommend(
            $this->forecasts->forHousehold($household),
            ($analysis ?? $this->analyze($household))->models,
            $this->targets($household),
        );
    }

    /**
     * Les recommandations du foyer entier et de chaque pièce, avec une seule lecture des prévisions.
     * Une pièce sans relevé reçoit l'estimation du foyer.
     *
     * @param list<Place> $places
     *
     * @throws \App\Forecast\HouseholdNotLocatedException
     * @throws \App\Forecast\ForecastUnavailableException
     */
    public function forHouseholdAndPlaces(Household $household, array $places, ?Analysis $analysis = null): RecommendationSet
    {
        $analysis ??= $this->analyze($household);
        $forecast = $this->forecasts->forHousehold($household);
        $targets = $this->targets($household);

        $byPlace = [];
        foreach ($places as $place) {
            $byPlace[(int) $place->getId()] = $this->engine->recommend($forecast, $analysis->modelsFor($place->getName()), $this->targets($household, $place));
        }

        return new RecommendationSet($this->engine->recommend($forecast, $analysis->models, $targets), $byPlace);
    }

    /**
     * Analyse les relevés du foyer : une seule lecture en base pour les moyennes et les modèles.
     */
    public function analyze(Household $household): Analysis
    {
        $readings = $this->readings->findByHousehold($household);

        $placesByDay = [];
        foreach ($this->heatingStarts->findByHousehold($household) as $start) {
            $placesByDay[$start->getStartedAt()->format('Y-m-d')][] = mb_strtolower($start->getPlace()->getName());
        }

        return new Analysis($this->calculator->calculate($readings), $this->fitter->fit($readings), $this->fitter->fitPlaces($readings), new HeatingDays($placesByDay));
    }

    /**
     * Températures visées par jour de la semaine puis par créneau : celles du foyer, remplacées pour
     * une pièce par ses propres températures là où elle en a.
     *
     * @return array<int, array<string, float>>
     */
    private function targets(Household $household, ?Place $place = null): array
    {
        $targets = [];
        foreach (Weekday::cases() as $day) {
            foreach (DaySlot::cases() as $slot) {
                $targets[$day->value][$slot->value] = $place?->targetFor($slot) ?? $household->targetFor($day, $slot)->getTemperature();
            }
        }

        return $targets;
    }
}
