<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Résultat du calcul pour un foyer : un détail par lieu et la synthèse de tous les lieux.
 */
final readonly class DeltaReport
{
    /**
     * @param list<PlaceDeltas> $places    triés par nom
     * @param PlaceDeltas       $household tous lieux confondus (chaque relevé compte pour un)
     */
    public function __construct(
        public array $places,
        public PlaceDeltas $household,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->household->overall->count;
    }
}
