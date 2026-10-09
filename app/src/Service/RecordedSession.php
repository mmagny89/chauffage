<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ce qu'un enregistrement de relevé a écrit : les relevés, et les allumages du chauffage notés avec.
 */
final readonly class RecordedSession
{
    /**
     * @param list<string> $heatedPlaces noms des pièces dont l'allumage a été noté
     */
    public function __construct(
        public int $readings,
        public array $heatedPlaces = [],
    ) {
    }
}
