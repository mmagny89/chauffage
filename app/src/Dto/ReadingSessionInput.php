<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Reading;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un relevé : à une date et une heure, la température extérieure et la température
 * intérieure de chaque lieu déclaré du foyer.
 */
final class ReadingSessionInput
{
    #[Assert\NotNull(message: 'Indiquez la date.')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\NotNull(message: 'Indiquez l’heure de la prise.')]
    public ?\DateTimeImmutable $time = null;

    #[Assert\NotNull(message: 'Indiquez la température extérieure.')]
    #[Assert\Range(min: Reading::OUTDOOR_MIN, max: Reading::OUTDOOR_MAX, notInRangeMessage: 'La température extérieure doit être comprise entre {{ min }} et {{ max }} °C.')]
    public ?float $outdoor = null;

    /**
     * Température intérieure par lieu, indexée par « p » suivi de l'identifiant du lieu.
     *
     * @var array<string, float|null>
     */
    public array $indoor = [];
}
