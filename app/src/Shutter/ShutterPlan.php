<?php

declare(strict_types=1);

namespace App\Shutter;

enum ShutterPlan: string
{
    /** Il fait froid et le soleil est prévu : ouvrir en journée pour récupérer sa chaleur. */
    case OpenForSun = 'open_for_sun';

    /** Il fait froid mais le ciel reste couvert : rien à gagner dehors, les volets fermés protègent. */
    case StayClosed = 'stay_closed';

    /** Il ne fait pas assez froid pour que les volets pèsent sur le chauffage. */
    case Mild = 'mild';
}
