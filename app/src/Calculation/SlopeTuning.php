<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * Résultat de la recherche d'une meilleure pente typique pour un foyer.
 */
final readonly class SlopeTuning
{
    public function __construct(
        /** Pente actuellement appliquée. */
        public float $current,
        /** Pente qui donne la plus petite erreur moyenne (la pente actuelle si aucune ne fait mieux). */
        public float $best,
        /** Erreur absolue moyenne avec la pente actuelle, en °C ; null si l'évaluation est impossible. */
        public ?float $currentError,
        /** Erreur absolue moyenne avec la meilleure pente, en °C. */
        public ?float $bestError,
        /** Vrai quand il y a assez de séances de relevés pour que la recherche ait un sens. */
        public bool $enoughData,
    ) {
    }

    /**
     * Vrai si changer de pente réduit l'erreur moyenne d'au moins SlopeTuner::MIN_GAIN.
     */
    public function isWorthApplying(): bool
    {
        return $this->enoughData && $this->best !== $this->current;
    }
}
