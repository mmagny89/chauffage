<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Reading;
use App\Service\RoomCatalog;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une ligne du formulaire : un lieu, l'heure de la prise et les deux températures.
 */
final class ReadingRowInput
{
    #[Assert\NotBlank(message: 'Choisissez le lieu.')]
    public ?string $place = null;

    #[Assert\Length(max: 80, maxMessage: 'Le nom du lieu ne doit pas dépasser {{ limit }} caractères.')]
    public ?string $customPlace = null;

    #[Assert\NotNull(message: 'Indiquez l’heure de la prise.')]
    public ?\DateTimeImmutable $time = null;

    #[Assert\NotNull(message: 'Indiquez la température extérieure.')]
    #[Assert\Range(min: Reading::OUTDOOR_MIN, max: Reading::OUTDOOR_MAX, notInRangeMessage: 'La température extérieure doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $outdoor = null;

    #[Assert\NotNull(message: 'Indiquez la température intérieure.')]
    #[Assert\Range(min: Reading::INDOOR_MIN, max: Reading::INDOOR_MAX, notInRangeMessage: 'La température intérieure doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $indoor = null;

    /**
     * Le nom du lieu : celui de la liste, ou celui saisi quand « Autre lieu… » est choisi.
     */
    public function placeName(): ?string
    {
        $name = RoomCatalog::OTHER === $this->place ? $this->customPlace : $this->place;
        $name = null === $name ? null : trim($name);

        return '' === $name ? null : $name;
    }

    #[Assert\Callback]
    public function requireCustomName(ExecutionContextInterface $context): void
    {
        if (RoomCatalog::OTHER === $this->place && null === $this->placeName()) {
            $context->buildViolation('Indiquez le nom du lieu.')->atPath('customPlace')->addViolation();
        }
    }
}
