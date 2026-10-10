<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Écrit une durée en minutes pour l'affichage : « 45 min », « 2 h », « 1 h 30 ».
 */
final class DurationExtension
{
    #[AsTwigFilter('duration')]
    public function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            0 === $hours => \sprintf('%d min', $rest),
            0 === $rest => \sprintf('%d h', $hours),
            default => \sprintf('%d h %02d', $hours, $rest),
        };
    }
}
