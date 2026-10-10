<?php

declare(strict_types=1);

namespace App\Reminder;

use App\Enum\DaySlot;

/**
 * Ce qu'on suggère de relever, et pourquoi. Les champs facultatifs ne sont renseignés que pour
 * la raison qui les utilise.
 */
final readonly class Reminder
{
    public function __construct(
        public ReminderReason $reason,
        /** Température extérieure annoncée (OutOfRange). */
        public ?float $forecast = null,
        public ?\DateTimeImmutable $date = null,
        public ?DaySlot $slot = null,
        /** Plage extérieure déjà relevée (OutOfRange). */
        public ?float $measuredMin = null,
        public ?float $measuredMax = null,
        /** Jours depuis le dernier relevé (Stale). */
        public int $daysSince = 0,
        /** Jours de relevés qui manquent au calibrage (Calibration). */
        public int $daysMissing = 0,
    ) {
    }
}
