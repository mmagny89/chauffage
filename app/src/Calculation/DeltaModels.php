<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Enum\DaySlot;

/**
 * Les modèles d'écart d'un foyer : un par créneau ayant des relevés, et un général
 * (tous créneaux) auquel on se rabat pour un créneau sans relevé.
 */
final readonly class DeltaModels
{
    /**
     * @param array<string, DeltaModel> $bySlot indexé par la valeur de DaySlot
     */
    public function __construct(
        public array $bySlot,
        public ?DeltaModel $overall,
    ) {
    }

    public function forSlot(DaySlot $slot): ?DeltaModel
    {
        return $this->bySlot[$slot->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return null === $this->overall;
    }
}
