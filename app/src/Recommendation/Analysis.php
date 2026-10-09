<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\DeltaModels;
use App\Calculation\DeltaReport;

/**
 * Ce que disent les relevés d'un foyer : les moyennes à afficher et les modèles à appliquer.
 */
final readonly class Analysis
{
    public function __construct(
        public DeltaReport $report,
        public DeltaModels $models,
    ) {
    }
}
