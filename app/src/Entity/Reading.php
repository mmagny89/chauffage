<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DaySlot;
use App\Repository\ReadingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Un relevé : températures extérieure et intérieure d'un lieu à un instant.
 *
 * `measuredAt` est l'heure murale locale du foyer (sans fuseau) : c'est elle qui
 * décide du créneau, et c'est celle que la personne a saisie.
 */
#[ORM\Entity(repositoryClass: ReadingRepository::class)]
#[ORM\Table(name: 'reading')]
#[ORM\UniqueConstraint(name: 'uniq_reading_place_measured_at', fields: ['place', 'measuredAt'])]
#[UniqueEntity(fields: ['place', 'measuredAt'], message: 'Un relevé existe déjà pour ce lieu à cette heure.', errorPath: 'measuredAt')]
class Reading
{
    public const OUTDOOR_MIN = -40.0;
    public const OUTDOOR_MAX = 50.0;
    public const INDOOR_MIN = 0.0;
    public const INDOOR_MAX = 40.0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Place $place;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1)]
    private string $outdoorTemperature;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1)]
    private string $indoorTemperature;

    public function __construct(Place $place, \DateTimeImmutable $measuredAt, float $outdoorTemperature, float $indoorTemperature)
    {
        if ($outdoorTemperature < self::OUTDOOR_MIN || $outdoorTemperature > self::OUTDOOR_MAX) {
            throw new \InvalidArgumentException(\sprintf('La température extérieure doit être comprise entre %d et %d °C.', self::OUTDOOR_MIN, self::OUTDOOR_MAX));
        }
        if ($indoorTemperature < self::INDOOR_MIN || $indoorTemperature > self::INDOOR_MAX) {
            throw new \InvalidArgumentException(\sprintf('La température intérieure doit être comprise entre %d et %d °C.', self::INDOOR_MIN, self::INDOOR_MAX));
        }

        $this->place = $place;
        $this->measuredAt = $measuredAt;
        $this->outdoorTemperature = number_format($outdoorTemperature, 1, '.', '');
        $this->indoorTemperature = number_format($indoorTemperature, 1, '.', '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): Place
    {
        return $this->place;
    }

    public function getMeasuredAt(): \DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function getOutdoorTemperature(): float
    {
        return (float) $this->outdoorTemperature;
    }

    public function getIndoorTemperature(): float
    {
        return (float) $this->indoorTemperature;
    }

    /**
     * Écart intérieur − extérieur, arrondi à la décimale saisie.
     */
    public function getDelta(): float
    {
        return round($this->getIndoorTemperature() - $this->getOutdoorTemperature(), 1);
    }

    public function getSlot(): DaySlot
    {
        return DaySlot::fromDateTime($this->measuredAt);
    }
}
