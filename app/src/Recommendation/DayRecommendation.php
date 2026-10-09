<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Enum\DaySlot;

final readonly class DayRecommendation
{
    /**
     * @param array<string, SlotRecommendation> $slots indexé par la valeur de DaySlot
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $slots,
    ) {
    }

    public function forSlot(DaySlot $slot): SlotRecommendation
    {
        return $this->slots[$slot->value];
    }
}
