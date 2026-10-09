<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaceRepository;
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

    public function __construct(Household $household, string $name)
    {
        $this->household = $household;
        $this->setName($name);
        $this->createdAt = new \DateTimeImmutable();
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
}
