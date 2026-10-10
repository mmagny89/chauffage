<?php

declare(strict_types=1);

namespace App\Alert;

use App\Enum\DaySlot;

/**
 * Le froid annoncé : son niveau, le minimum prévu et le créneau où il tombe.
 */
final readonly class FrostAlert
{
    public function __construct(
        public FrostLevel $level,
        public float $minimum,
        public \DateTimeImmutable $date,
        public DaySlot $slot,
    ) {
    }
}
