<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HeatingStartRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un allumage du chauffage : à quel moment il a été mis en route dans une pièce, sur quelle
 * température de consigne, et quelle température il faisait alors dans la pièce.
 *
 * Ce n'est pas un relevé : il est pris chauffage allumé et ne sert jamais au calcul des écarts.
 * `startedAt` est l'heure murale locale du foyer (sans fuseau), comme celle d'un relevé.
 */
#[ORM\Entity(repositoryClass: HeatingStartRepository::class)]
#[ORM\Table(name: 'heating_start')]
#[ORM\UniqueConstraint(name: 'uniq_heating_start_place_started_at', fields: ['place', 'startedAt'])]
class HeatingStart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Place $place;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1)]
    private string $setpoint;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1)]
    private string $indoorTemperature;

    /** Absente des allumages notés avant que la température extérieure soit demandée. */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $outdoorTemperature = null;

    public function __construct(Place $place, \DateTimeImmutable $startedAt, float $setpoint, float $indoorTemperature, float $outdoorTemperature)
    {
        if ($setpoint < HeatingTarget::MIN || $setpoint > HeatingTarget::MAX) {
            throw new \InvalidArgumentException(\sprintf('La consigne doit être comprise entre %d et %d °C.', HeatingTarget::MIN, HeatingTarget::MAX));
        }
        if ($indoorTemperature < Reading::INDOOR_MIN || $indoorTemperature > Reading::INDOOR_MAX) {
            throw new \InvalidArgumentException(\sprintf('La température intérieure doit être comprise entre %d et %d °C.', Reading::INDOOR_MIN, Reading::INDOOR_MAX));
        }

        if ($outdoorTemperature < Reading::OUTDOOR_MIN || $outdoorTemperature > Reading::OUTDOOR_MAX) {
            throw new \InvalidArgumentException(\sprintf('La température extérieure doit être comprise entre %d et %d °C.', Reading::OUTDOOR_MIN, Reading::OUTDOOR_MAX));
        }

        $this->place = $place;
        $this->startedAt = $startedAt;
        $this->setpoint = number_format($setpoint, 1, '.', '');
        $this->indoorTemperature = number_format($indoorTemperature, 1, '.', '');
        $this->outdoorTemperature = number_format($outdoorTemperature, 1, '.', '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): Place
    {
        return $this->place;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getSetpoint(): float
    {
        return (float) $this->setpoint;
    }

    public function getIndoorTemperature(): float
    {
        return (float) $this->indoorTemperature;
    }

    public function getOutdoorTemperature(): ?float
    {
        return null === $this->outdoorTemperature ? null : (float) $this->outdoorTemperature;
    }

    /**
     * Combien de degrés la consigne demande en plus de la température de la pièce.
     */
    public function getGap(): float
    {
        return round($this->getSetpoint() - $this->getIndoorTemperature(), 1);
    }
}
