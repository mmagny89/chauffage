<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enum\DaySlot;

final readonly class DayForecast
{
    /**
     * @param array<string, SlotForecast> $slots indexé par la valeur de DaySlot
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $slots,
    ) {
    }

    public function forSlot(DaySlot $slot): SlotForecast
    {
        return $this->slots[$slot->value];
    }
}
