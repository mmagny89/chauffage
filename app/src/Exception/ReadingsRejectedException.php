<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Des lignes saisies ne peuvent pas être enregistrées ; chaque motif est rattaché
 * à l'index de sa ligne pour s'afficher au bon endroit du formulaire.
 */
final class ReadingsRejectedException extends \RuntimeException
{
    /**
     * @param array<int, string> $reasons message par index de ligne
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Relevés refusés.');
    }
}
