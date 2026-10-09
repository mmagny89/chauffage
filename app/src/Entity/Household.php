<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DaySlot;
use App\Repository\HouseholdRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le foyer d'un compte : un compte, un foyer. Porte la localisation (pour les
 * prévisions) et les températures visées par créneau de la journée.
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

    /**
     * @var Collection<int, HeatingTarget>
     */
    #[ORM\OneToMany(targetEntity: HeatingTarget::class, mappedBy: 'household', cascade: ['persist'])]
    private Collection $targets;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->targets = new ArrayCollection();

        foreach (DaySlot::cases() as $slot) {
            $this->targets->add(new HeatingTarget($this, $slot, $slot->defaultTarget()));
        }
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

    public function targetFor(DaySlot $slot): HeatingTarget
    {
        foreach ($this->targets as $target) {
            if ($target->getSlot() === $slot) {
                return $target;
            }
        }

        throw new \LogicException(\sprintf('Aucune température visée pour le créneau « %s ».', $slot->value));
    }
}
