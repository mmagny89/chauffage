<?php

declare(strict_types=1);

namespace App\Recommendation;

enum HeatingAction: string
{
    /** La température intérieure estimée est sous la température visée : chauffer. */
    case Heat = 'heat';

    /** Les températures suffisent : le chauffage peut être coupé. */
    case Cut = 'cut';

    /** Pas assez d'éléments (aucun relevé, ou pas de prévision) pour conclure. */
    case Unknown = 'unknown';
}
