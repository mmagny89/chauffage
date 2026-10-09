<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Classe une température extérieure en une teinte (« freezing » à « warm ») que les gabarits
 * traduisent en couleur de fond : le seuil est de la logique, la couleur de la présentation.
 */
final class TemperatureToneExtension
{
    #[AsTwigFunction('temperature_tone')]
    public function toneFor(float $celsius): string
    {
        return match (true) {
            $celsius < 0 => 'freezing',
            $celsius < 8 => 'cold',
            $celsius < 15 => 'cool',
            $celsius < 21 => 'mild',
            default => 'warm',
        };
    }
}
