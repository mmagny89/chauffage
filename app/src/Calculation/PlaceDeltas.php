<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Enum\DaySlot;

/**
 * Écarts moyens d'un lieu : tous créneaux confondus, puis créneau par créneau.
 */
final readonly class PlaceDeltas
{
    /**
     * @param array<string, SlotDelta> $bySlot indexé par la valeur de DaySlot
     */
    public function __construct(
        public string $placeName,
        public SlotDelta $overall,
        public array $bySlot,
    ) {
    }

    public function forSlot(DaySlot $slot): SlotDelta
    {
        return $this->bySlot[$slot->value] ?? SlotDelta::none();
    }
}
