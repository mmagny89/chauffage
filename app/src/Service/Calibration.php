<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Nombre de jours de relevés à réunir avant de se fier aux écarts moyens.
 * C'est un minimum : au-delà, plus il y a de jours, plus la moyenne est fiable.
 */
final class Calibration
{
    public const DAYS_REQUIRED = 5;
}
