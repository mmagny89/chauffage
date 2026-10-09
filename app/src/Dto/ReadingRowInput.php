<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Reading;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une ligne du formulaire : un lieu, l'heure de la prise et les deux températures.
 */
final class ReadingRowInput
{
    #[Assert\NotBlank(message: 'Indiquez le lieu.')]
    #[Assert\Length(max: 80, maxMessage: 'Le nom du lieu ne doit pas dépasser {{ limit }} caractères.')]
    public ?string $place = null;

    #[Assert\NotNull(message: 'Indiquez l’heure de la prise.')]
    public ?\DateTimeImmutable $time = null;

    #[Assert\NotNull(message: 'Indiquez la température extérieure.')]
    #[Assert\Range(min: Reading::OUTDOOR_MIN, max: Reading::OUTDOOR_MAX, notInRangeMessage: 'La température extérieure doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $outdoor = null;

    #[Assert\NotNull(message: 'Indiquez la température intérieure.')]
    #[Assert\Range(min: Reading::INDOOR_MIN, max: Reading::INDOOR_MAX, notInRangeMessage: 'La température intérieure doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $indoor = null;
}
