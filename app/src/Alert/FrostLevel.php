<?php

declare(strict_types=1);

namespace App\Alert;

enum FrostLevel: string
{
    /** La température prévue passe sous 0 °C. */
    case Frost = 'frost';

    /** La température prévue atteint -5 °C ou moins. */
    case Severe = 'severe';
}
