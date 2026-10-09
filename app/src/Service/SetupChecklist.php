<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Household;
use App\Repository\PlaceRepository;

/**
 * Évalue la mise en route d'un foyer : une ville (pour les prévisions) et au moins un lieu
 * (pour les relevés). Les températures visées ont des valeurs par défaut ; l'utilisateur les
 * relit avant de terminer.
 */
final readonly class SetupChecklist
{
    public function __construct(private PlaceRepository $places)
    {
    }

    public function progress(Household $household): SetupProgress
    {
        return new SetupProgress(
            null !== $household->getLatitude() && null !== $household->getLongitude(),
            $this->places->count(['household' => $household]),
        );
    }
}
