<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Les jours où le chauffage a été allumé, et dans quelles pièces, d'après les allumages notés.
 */
final readonly class HeatingDays
{
    /**
     * @param array<string, list<string>> $placesByDay noms des pièces (en minuscules) où le chauffage a été allumé, indexés par jour (Y-m-d)
     */
    public function __construct(private array $placesByDay = [])
    {
    }

    /**
     * @param string|null $placeName nom de la pièce ; null pour le foyer entier (une pièce quelconque)
     */
    public function has(string $date, ?string $placeName = null): bool
    {
        $places = $this->placesByDay[$date] ?? [];

        return null === $placeName ? [] !== $places : \in_array(mb_strtolower($placeName), $places, true);
    }
}
