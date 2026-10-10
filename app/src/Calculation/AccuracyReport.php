<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Précision des estimations sur les relevés du foyer.
 */
final readonly class AccuracyReport
{
    /**
     * @param array<string, AccuracyStats> $bySlot  indexé par la valeur de DaySlot
     * @param array<string, AccuracyStats> $byPlace indexé par le nom du lieu, trié
     */
    public function __construct(
        public ?AccuracyStats $overall,
        public array $bySlot,
        public array $byPlace,
        public int $sessions,
        public int $skipped,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->overall;
    }

    /**
     * Vrai tant qu'il y a trop peu de séances de relevés pour conclure.
     */
    public function isProvisional(): bool
    {
        return $this->sessions < AccuracyEvaluator::RELIABLE_FROM_SESSIONS;
    }
}
