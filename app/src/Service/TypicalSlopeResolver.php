<?php

declare(strict_types=1);

namespace App\Service;

use App\Calculation\DeltaModelFitter;
use App\Calculation\SlopeTuner;
use App\Calculation\SlopeTuning;
use App\Entity\Household;
use App\Entity\Reading;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * La pente typique de l'écart appliquée à un foyer : celle qu'il a réglée, la valeur par défaut, ou —
 * en réglage automatique — celle que SlopeTuner juge la meilleure sur ses relevés.
 *
 * La recherche coûte plusieurs évaluations complètes : son résultat est gardé en cache, sous une clé
 * qui dépend du contenu des relevés. Ajouter ou supprimer un relevé change la clé ; rien à invalider.
 */
final readonly class TypicalSlopeResolver
{
    private const CACHE_TTL = 30 * 86400;

    public function __construct(
        private SlopeTuner $tuner,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<Reading> $readings
     */
    public function effective(Household $household, array $readings): float
    {
        if ($household->isAutoTuneSlope()) {
            return $this->tuning($household, $readings)->best;
        }

        return $household->getTypicalSlope() ?? DeltaModelFitter::TYPICAL_SLOPE;
    }

    /**
     * La recherche d'une meilleure pente. En réglage automatique elle part de la valeur par défaut (le
     * résultat ne dépend alors pas d'un ancien réglage manuel) ; sinon de la pente actuelle du foyer.
     *
     * @param list<Reading> $readings
     */
    public function tuning(Household $household, array $readings): SlopeTuning
    {
        $current = $household->isAutoTuneSlope()
            ? DeltaModelFitter::TYPICAL_SLOPE
            : ($household->getTypicalSlope() ?? DeltaModelFitter::TYPICAL_SLOPE);

        $fingerprint = hash('sha256', implode(';', array_map(
            static fn (Reading $r): string => \sprintf('%s|%s|%s|%s', $r->getMeasuredAt()->format('YmdHi'), $r->getPlace()->getName(), $r->getOutdoorTemperature(), $r->getIndoorTemperature()),
            $readings,
        )));

        return $this->cache->get(
            \sprintf('slope_tuning.%s.%s', number_format($current, 2, '.', ''), $fingerprint),
            function (ItemInterface $item) use ($readings, $current): SlopeTuning {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->tuner->tune($readings, $current);
            },
        );
    }
}
