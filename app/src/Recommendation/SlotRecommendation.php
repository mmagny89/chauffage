<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Calculation\ModelKind;
use App\Enum\DaySlot;

/**
 * Ce qu'il faut faire du chauffage sur un créneau, et sur quoi cela repose.
 */
final readonly class SlotRecommendation
{
    public function __construct(
        public DaySlot $slot,
        public HeatingAction $action,
        public float $target,
        public ?float $outdoor,
        public ?float $delta,
        public ?float $estimatedIndoor,
        public int $deltaSamples,
        public bool $deltaIsFallback,
        public bool $forecastComplete,
        public ?ModelKind $method = null,
        public bool $extrapolated = false,
        public bool $heatingOn = false,
    ) {
    }

    /**
     * Consigne à régler quand il faut chauffer : la température visée.
     */
    public function setpoint(): ?float
    {
        return HeatingAction::Heat === $this->action ? $this->target : null;
    }

    /**
     * Le même créneau, marqué comme « chauffage déjà allumé » : l'affichage dit alors « Chauffer » sans
     * température, la consigne étant déjà réglée.
     */
    public function withHeatingOn(): self
    {
        return new self($this->slot, $this->action, $this->target, $this->outdoor, $this->delta, $this->estimatedIndoor, $this->deltaSamples, $this->deltaIsFallback, $this->forecastComplete, $this->method, $this->extrapolated, true);
    }

    public static function unknown(DaySlot $slot, float $target, ?float $outdoor, bool $forecastComplete): self
    {
        return new self($slot, HeatingAction::Unknown, $target, $outdoor, null, null, 0, false, $forecastComplete);
    }
}
