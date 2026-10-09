<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les quatre moments de la journée sur lesquels portent les écarts de température
 * et les recommandations. Les bornes sont en heure locale du foyer.
 */
enum DaySlot: string
{
    case Night = 'night';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    /**
     * Nuit : 22 h – 6 h, matin : 6 h – 12 h, après-midi : 12 h – 18 h, soirée : 18 h – 22 h.
     */
    public static function fromHour(int $hour): self
    {
        return match (true) {
            $hour < 0 || $hour > 23 => throw new \InvalidArgumentException(\sprintf('Heure invalide : %d.', $hour)),
            $hour < 6 || $hour >= 22 => self::Night,
            $hour < 12 => self::Morning,
            $hour < 18 => self::Afternoon,
            default => self::Evening,
        };
    }

    public static function fromDateTime(\DateTimeInterface $dateTime): self
    {
        return self::fromHour((int) $dateTime->format('G'));
    }

    public function label(): string
    {
        return match ($this) {
            self::Night => 'Nuit',
            self::Morning => 'Matin',
            self::Afternoon => 'Après-midi',
            self::Evening => 'Soirée',
        };
    }

    /**
     * Température intérieure visée par défaut, en °C.
     */
    public function defaultTarget(): float
    {
        return match ($this) {
            self::Night => 17.0,
            self::Morning, self::Afternoon => 19.0,
            self::Evening => 20.0,
        };
    }
}
