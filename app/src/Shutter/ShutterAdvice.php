<?php

declare(strict_types=1);

namespace App\Shutter;

final readonly class ShutterAdvice
{
    public function __construct(
        public \DateTimeImmutable $date,
        public ShutterPlan $plan,
        public \DateTimeImmutable $sunrise,
        public \DateTimeImmutable $sunset,
        public int $sunshineMinutes,
    ) {
    }
}
