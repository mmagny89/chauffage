<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Où en est la mise en route d'un foyer : ce qui est fait, et ce qui manque pour la terminer.
 */
final readonly class SetupProgress
{
    public function __construct(
        public bool $hasCity,
        public int $placeCount,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->hasCity && $this->placeCount > 0;
    }

    /**
     * @return list<string> ce qui manque, en phrases pour l'utilisateur
     */
    public function missing(): array
    {
        $missing = [];
        if (!$this->hasCity) {
            $missing[] = 'choisissez votre ville';
        }
        if ($this->placeCount < 1) {
            $missing[] = 'déclarez au moins un lieu';
        }

        return $missing;
    }
}
