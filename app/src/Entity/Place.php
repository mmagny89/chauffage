<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DaySlot;
use App\Repository\PlaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une pièce ou un endroit où l'on relève la température (salon, chambre, cave…).
 */
#[ORM\Entity(repositoryClass: PlaceRepository::class)]
#[ORM\Table(name: 'place')]
#[ORM\UniqueConstraint(name: 'uniq_place_household_name', fields: ['household', 'name'])]
#[UniqueEntity(fields: ['household', 'name'], message: 'Ce lieu existe déjà dans votre foyer.', errorPath: 'name')]
class Place
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un nom au lieu.')]
    #[Assert\Length(max: 80)]
    private string $name = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, PlaceTarget>
     */
    #[ORM\OneToMany(targetEntity: PlaceTarget::class, mappedBy: 'place', cascade: ['persist'], orphanRemoval: true)]
    private Collection $targets;

    public function __construct(Household $household, string $name)
    {
        $this->household = $household;
        $this->setName($name);
        $this->createdAt = new \DateTimeImmutable();
        $this->targets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    /**
     * @return Collection<int, PlaceTarget>
     */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    /**
     * Vrai si la pièce a au moins une température visée propre : seules ces pièces ont leur
     * recommandation, les autres suivent le foyer.
     */
    public function hasOwnTargets(): bool
    {
        return !$this->targets->isEmpty();
    }

    /**
     * La température visée propre à cette pièce pour ce créneau, ou null si elle suit le foyer.
     */
    public function targetFor(DaySlot $slot): ?float
    {
        foreach ($this->targets as $target) {
            if ($target->getSlot() === $slot) {
                return $target->getTemperature();
            }
        }

        return null;
    }

    /**
     * Fixe la température visée de la pièce pour un créneau ; null la retire (la pièce suit alors le foyer).
     */
    public function setTarget(DaySlot $slot, ?float $temperature): void
    {
        foreach ($this->targets as $target) {
            if ($target->getSlot() === $slot) {
                if (null === $temperature) {
                    $this->targets->removeElement($target);
                } else {
                    $target->setTemperature($temperature);
                }

                return;
            }
        }

        if (null !== $temperature) {
            $this->targets->add(new PlaceTarget($this, $slot, $temperature));
        }
    }
}
