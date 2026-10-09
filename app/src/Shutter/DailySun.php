<?php

declare(strict_types=1);

namespace App\Shutter;

/**
 * Ce que la météo dit d'un jour pour les volets, à l'heure murale locale du foyer.
 */
final readonly class DailySun
{
    /**
     * @param int $meanTenths      température moyenne du jour, en dixièmes de degré
     * @param int $sunshineSeconds durée d'ensoleillement prévue
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public \DateTimeImmutable $sunrise,
        public \DateTimeImmutable $sunset,
        public int $sunshineSeconds,
        public int $meanTenths,
    ) {
    }
}
