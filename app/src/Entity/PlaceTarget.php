<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DaySlot;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Température intérieure visée pour une pièce à un moment de la journée. Elle remplace, pour
 * cette pièce et ce créneau, la température du foyer (qui dépend aussi du jour de la semaine) ;
 * sans enregistrement, la pièce suit le foyer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'place_target')]
#[ORM\UniqueConstraint(name: 'uniq_place_target_place_slot', fields: ['place', 'slot'])]
class PlaceTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Place $place;

    #[ORM\Column(length: 16, enumType: DaySlot::class)]
    private DaySlot $slot;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1)]
    private string $temperature;

    public function __construct(Place $place, DaySlot $slot, float $temperature)
    {
        $this->place = $place;
        $this->slot = $slot;
        $this->setTemperature($temperature);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): Place
    {
        return $this->place;
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
        if ($temperature < HeatingTarget::MIN || $temperature > HeatingTarget::MAX) {
            throw new \InvalidArgumentException(\sprintf('La température visée doit être comprise entre %d et %d °C.', HeatingTarget::MIN, HeatingTarget::MAX));
        }

        $this->temperature = number_format($temperature, 1, '.', '');

        return $this;
    }
}
