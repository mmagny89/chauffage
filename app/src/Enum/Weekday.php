<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Jour de la semaine, numéroté comme le format « N » de PHP (ISO 8601, lundi = 1).
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDate(\DateTimeInterface $date): self
    {
        return self::from((int) $date->format('N'));
    }

    /**
     * Clé stable pour les noms de champs de formulaire : « monday », « tuesday »…
     */
    public function key(): string
    {
        return strtolower($this->name);
    }

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'Lundi',
            self::Tuesday => 'Mardi',
            self::Wednesday => 'Mercredi',
            self::Thursday => 'Jeudi',
            self::Friday => 'Vendredi',
            self::Saturday => 'Samedi',
            self::Sunday => 'Dimanche',
        };
    }
}
