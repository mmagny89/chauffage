<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Écart moyen (intérieur − extérieur) sur un ensemble de relevés.
 * `average` est nul tant qu'aucun relevé n'existe.
 */
final readonly class SlotDelta
{
    public function __construct(
        public int $count,
        public ?float $average,
    ) {
    }

    public static function none(): self
    {
        return new self(0, null);
    }

    /**
     * @param int $sumTenths somme des écarts, en dixièmes de degré (entiers : pas de dérive de flottants)
     */
    public static function fromTenths(int $sumTenths, int $count): self
    {
        if (0 === $count) {
            return self::none();
        }

        return new self($count, round($sumTenths / $count) / 10);
    }
}
