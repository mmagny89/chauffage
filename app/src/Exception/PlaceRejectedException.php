<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Un lieu ne peut pas être créé ou renommé ; le message est destiné à l'utilisateur.
 */
final class PlaceRejectedException extends \RuntimeException
{
}
