<?php

declare(strict_types=1);

namespace App\Entity;

use App\Calculation\DeltaModelFitter;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Repository\HouseholdRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le foyer d'un compte : un compte, un foyer. Porte la localisation (pour les
 * prévisions) et les températures visées par jour de la semaine et par créneau.
 */
#[ORM\Entity(repositoryClass: HouseholdRepository::class)]
#[ORM\Table(name: 'household')]
class Household
{
    public const DEFAULT_TIMEZONE = 'Europe/Paris';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $city = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 5, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 5, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(length: 64)]
    #[Assert\Timezone]
    private string $timezone = self::DEFAULT_TIMEZONE;

    /** Date à laquelle la mise en route (ville, lieux, températures visées) a été terminée ; null tant qu'elle ne l'est pas. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $setupCompletedAt = null;

    /** Pente typique de l'écart propre au foyer (de -0,9 à -0,1) ; null : valeur par défaut. */
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
    private ?string $typicalSlope = null;

    /** Vrai : la pente typique est choisie par l'outil d'après la fiabilité mesurée sur les relevés. */
    #[ORM\Column(options: ['default' => false])]
    private bool $autoTuneSlope = false;

    /**
     * @var Collection<int, HeatingTarget>
     */
    #[ORM\OneToMany(targetEntity: HeatingTarget::class, mappedBy: 'household', cascade: ['persist'])]
    private Collection $targets;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->targets = new ArrayCollection();

        $this->completeTargets();
    }

    /**
     * Ajoute les températures visées manquantes (valeurs par défaut), sans toucher aux existantes.
     *
     * @return int nombre de températures ajoutées
     */
    public function completeTargets(): int
    {
        $added = 0;
        foreach (Weekday::cases() as $day) {
            foreach (DaySlot::cases() as $slot) {
                if (!$this->hasTarget($day, $slot)) {
                    $this->targets->add(new HeatingTarget($this, $day, $slot, $slot->defaultTarget()));
                    ++$added;
                }
            }
        }

        return $added;
    }

    private function hasTarget(Weekday $day, DaySlot $slot): bool
    {
        foreach ($this->targets as $target) {
            if ($target->getDayOfWeek() === $day && $target->getSlot() === $slot) {
                return true;
            }
        }

        return false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getLatitude(): ?float
    {
        return null === $this->latitude ? null : (float) $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return null === $this->longitude ? null : (float) $this->longitude;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function isSetUp(): bool
    {
        return null !== $this->setupCompletedAt;
    }

    public function completeSetup(\DateTimeImmutable $at): static
    {
        $this->setupCompletedAt ??= $at;

        return $this;
    }

    public function getTypicalSlope(): ?float
    {
        return null === $this->typicalSlope ? null : (float) $this->typicalSlope;
    }

    public function setTypicalSlope(?float $slope): static
    {
        if (null !== $slope && ($slope < DeltaModelFitter::SLOPE_MIN || $slope > DeltaModelFitter::SLOPE_MAX)) {
            throw new \InvalidArgumentException('La pente typique doit être comprise entre -0,9 et -0,1.');
        }
        $this->typicalSlope = null === $slope ? null : number_format($slope, 2, '.', '');

        return $this;
    }

    public function isAutoTuneSlope(): bool
    {
        return $this->autoTuneSlope;
    }

    public function setAutoTuneSlope(bool $auto): static
    {
        $this->autoTuneSlope = $auto;

        return $this;
    }

    public function locate(string $city, float $latitude, float $longitude, string $timezone): static
    {
        $this->city = $city;
        $this->latitude = number_format($latitude, 5, '.', '');
        $this->longitude = number_format($longitude, 5, '.', '');
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * @return Collection<int, HeatingTarget>
     */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    public function targetFor(Weekday $day, DaySlot $slot): HeatingTarget
    {
        foreach ($this->targets as $target) {
            if ($target->getDayOfWeek() === $day && $target->getSlot() === $slot) {
                return $target;
            }
        }

        throw new \LogicException(\sprintf('Aucune température visée pour « %s », créneau « %s ».', $day->key(), $slot->value));
    }
}
