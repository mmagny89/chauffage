<?php

declare(strict_types=1);

namespace App\Recommendation;

/**
 * Un créneau à venir (ou en cours) avec sa recommandation.
 */
final readonly class UpcomingSlot
{
    public function __construct(
        public \DateTimeImmutable $date,
        public SlotRecommendation $recommendation,
    ) {
    }
}
