<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Les limiteurs de débit gardent leur état d'une exécution à l'autre : les tests qui
 * déclenchent des limites le vident avant de commencer.
 *
 * À utiliser dans une classe qui étend KernelTestCase ou WebTestCase.
 */
trait ResetsRateLimiters
{
    private static function resetRateLimiters(): void
    {
        $pool = self::getContainer()->get('test.cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
    }
}
