<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\HeatingTarget;
use App\Enum\DaySlot;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Température intérieure visée pour chaque créneau, en °C.
 */
final class TargetsInput
{
    #[Assert\NotNull(message: 'Indiquez la température visée.')]
    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $night = null;

    #[Assert\NotNull(message: 'Indiquez la température visée.')]
    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $morning = null;

    #[Assert\NotNull(message: 'Indiquez la température visée.')]
    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $afternoon = null;

    #[Assert\NotNull(message: 'Indiquez la température visée.')]
    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $evening = null;

    public function for(DaySlot $slot): ?float
    {
        return match ($slot) {
            DaySlot::Night => $this->night,
            DaySlot::Morning => $this->morning,
            DaySlot::Afternoon => $this->afternoon,
            DaySlot::Evening => $this->evening,
        };
    }
}
