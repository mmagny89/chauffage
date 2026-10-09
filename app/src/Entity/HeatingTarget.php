<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DaySlot;
use App\Enum\Weekday;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Température intérieure en dessous de laquelle on souhaite chauffer, pour un créneau
 * d'un jour de la semaine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'heating_target')]
#[ORM\UniqueConstraint(name: 'uniq_heating_target_household_day_slot', fields: ['household', 'dayOfWeek', 'slot'])]
class HeatingTarget
{
    public const MIN = 5.0;
    public const MAX = 30.0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $dayOfWeek;

    #[ORM\Column(length: 16, enumType: DaySlot::class)]
    private DaySlot $slot;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1)]
    private string $temperature;

    public function __construct(Household $household, Weekday $dayOfWeek, DaySlot $slot, float $temperature)
    {
        $this->household = $household;
        $this->dayOfWeek = $dayOfWeek;
        $this->slot = $slot;
        $this->setTemperature($temperature);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getDayOfWeek(): Weekday
    {
        return $this->dayOfWeek;
    }

    public function getSlot(): DaySlot
    {
        return $this->slot;
    }

    public function getTemperature(): float
    {
        return (float) $this->temperature;
    }

    public function setTemperature(float $temperature): static
    {
        if ($temperature < self::MIN || $temperature > self::MAX) {
            throw new \InvalidArgumentException(\sprintf('La température visée doit être comprise entre %d et %d °C.', self::MIN, self::MAX));
        }

        $this->temperature = number_format($temperature, 1, '.', '');

        return $this;
    }
}
