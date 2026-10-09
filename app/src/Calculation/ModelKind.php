<?php

declare(strict_types=1);

namespace App\Calculation;

enum ModelKind: string
{
    /** Les relevés dominent : la pente de l'écart est surtout celle qu'ils mesurent. */
    case Regression = 'regression';

    /** Trop peu de relevés ou de variété : la pente est surtout la pente typique d'un logement sans chauffage. */
    case Typical = 'typical';
}
