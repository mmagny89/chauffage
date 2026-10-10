<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaModelFitter;
use App\Calculation\DeltaModels;
use App\Calculation\DeltaReport;
use App\Calculation\HeatingDays;
use App\Calculation\HeatingRates;

/**
 * Ce que disent les relevés d'un foyer : les moyennes à afficher et les modèles à appliquer,
 * pour le foyer entier et pièce par pièce.
 */
final readonly class Analysis
{
    /**
     * @param array<string, DeltaModels> $placeModels modèles de chaque pièce ayant des relevés, indexés par son nom en minuscules
     */
    public function __construct(
        public DeltaReport $report,
        public DeltaModels $models,
        public array $placeModels = [],
        public HeatingDays $heating = new HeatingDays(),
        public ?\DateTimeImmutable $lastReadingAt = null,
        /** Pente typique appliquée aux modèles : celle du foyer, ou la valeur par défaut. */
        public float $typicalSlope = DeltaModelFitter::TYPICAL_SLOPE,
        public HeatingRates $rates = new HeatingRates(),
    ) {
    }

    public function hasReadingsFor(string $placeName): bool
    {
        return isset($this->placeModels[mb_strtolower($placeName)]);
    }

    /**
     * Les modèles à appliquer à une pièce : les siens, ou ceux du foyer faute de relevé.
     */
    public function modelsFor(string $placeName): DeltaModels
    {
        return $this->placeModels[mb_strtolower($placeName)] ?? $this->models;
    }
}
