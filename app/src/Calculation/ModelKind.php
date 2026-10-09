<?php

declare(strict_types=1);

namespace App\Calculation;

enum ModelKind: string
{
    /** L'écart dépend de la température extérieure : écart = ordonnée + pente × extérieur. */
    case Regression = 'regression';

    /** Pas assez de relevés ou de variété : écart moyen constant. */
    case Mean = 'mean';
}
