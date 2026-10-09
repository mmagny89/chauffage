<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * La ville retenue parmi les résultats de la recherche, telle que renvoyée par le navigateur.
 */
final class CityChoiceInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -90, max: 90)]
    public ?float $latitude = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -180, max: 180)]
    public ?float $longitude = null;

    #[Assert\NotBlank]
    #[Assert\Timezone]
    public ?string $timezone = null;
}
