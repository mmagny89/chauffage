<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Un mur d'une pièce qui donne sur l'extérieur, désigné par son orientation.
 */
enum Exposure: string
{
    case North = 'north';
    case East = 'east';
    case South = 'south';
    case West = 'west';

    public function label(): string
    {
        return match ($this) {
            self::North => 'Mur Nord',
            self::East => 'Mur Est',
            self::South => 'Mur Sud',
            self::West => 'Mur Ouest',
        };
    }
}
