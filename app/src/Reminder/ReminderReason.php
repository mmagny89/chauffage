<?php

declare(strict_types=1);

namespace App\Reminder;

enum ReminderReason: string
{
    /** Une température annoncée sort de la plage déjà relevée : un relevé élargirait le modèle. */
    case OutOfRange = 'out_of_range';

    /** Le dernier relevé est ancien. */
    case Stale = 'stale';

    /** Le calibrage n'est pas fini et aucun relevé n'a été fait aujourd'hui. */
    case Calibration = 'calibration';
}
