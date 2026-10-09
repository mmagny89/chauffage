<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\HeatingTarget;
use App\Enum\DaySlot;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Températures visées propres à une pièce, par moment de la journée ; vide = la pièce suit le foyer.
 */
final class PlaceTargetsInput
{
    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $night = null;

    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $morning = null;

    #[Assert\Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La température visée doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $afternoon = null;

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
