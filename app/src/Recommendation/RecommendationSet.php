<?php

declare(strict_types=1);

namespace App\Recommendation;

/**
 * Les recommandations d'un foyer : pour le foyer entier, et pour chacune de ses pièces.
 */
final readonly class RecommendationSet
{
    /**
     * @param list<DayRecommendation>             $household recommandations du foyer entier
     * @param array<int, list<DayRecommendation>> $places    recommandations de chaque pièce, indexées par son identifiant
     */
    public function __construct(
        public array $household,
        public array $places,
    ) {
    }
}
