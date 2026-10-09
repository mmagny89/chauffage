<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\Reading;

/**
 * Ajuste, par créneau, l'écart intérieur − extérieur en fonction de la température
 * extérieure.
 *
 * Sans chauffage, l'intérieur suit le dehors de façon amortie : sa pente par rapport à
 * l'extérieur est inférieure à 1 (environ 0,6 pour des bâtiments non climatisés), donc l'écart
 * diminue quand il fait plus doux et grandit quand il fait plus froid. Appliquer un écart
 * constant mesuré par temps froid à une prévision douce annonce 30 °C dans un salon : c'est ce
 * que ce modèle évite.
 *
 * Régression des moindres carrés « tirée » vers une pente typique (régression à pénalité
 * quadratique) : pente = (Sxy + λ·pente_typique) / (Sxx + λ), où Sxx mesure la variété des
 * températures extérieures relevées. Avec un seul relevé, ou des relevés à la même température,
 * Sxx = 0 et la pente est la pente typique ; avec des relevés nombreux et variés, elle devient
 * celle qu'ils mesurent. Dans tous les cas la droite passe par le point moyen des mesures, et la
 * pente est bornée à [SLOPE_MIN ; SLOPE_MAX] (un intérieur indépendant du dehors, ou qui le
 * suit à l'identique, ne se rencontre pas dans un logement). Calcul pur.
 *
 * Hypothèses, à réviser avec des données réelles : la pente typique de l'écart (−0,4, soit
 * 0,6 pour l'intérieur) d'après la littérature sur les bâtiments non climatisés, et la force
 * du tirage λ = 25 °C² (bruit des relevés d'environ 1 °C face à une incertitude d'environ 0,2
 * sur la pente).
 */
final class DeltaModelFitter
{
    /** Pente typique de l'écart : intérieur ≈ 0,6 × extérieur + constante. */
    public const TYPICAL_SLOPE = -0.4;

    /** Force du tirage vers la pente typique, en °C² (Sxx de relevés équilibre cette valeur). */
    public const PRIOR_STRENGTH = 25.0;

    public const SLOPE_MIN = -0.9;
    public const SLOPE_MAX = -0.1;

    /** Part des relevés à partir de laquelle on parle de régression plutôt que de pente typique. */
    public const REGRESSION_WEIGHT = 0.5;

    /**
     * @param iterable<Reading> $readings
     */
    public function fit(iterable $readings): DeltaModels
    {
        /** @var array<string, non-empty-list<array{float, float, string}>> $bySlot */
        $bySlot = [];
        /** @var list<array{float, float, string}> $all */
        $all = [];

        foreach ($readings as $reading) {
            $point = [$reading->getOutdoorTemperature(), $reading->getDelta(), $reading->getMeasuredAt()->format('Y-m-d H:i')];
            $bySlot[$reading->getSlot()->value][] = $point;
            $all[] = $point;
        }

        if ([] === $all) {
            return new DeltaModels([], null);
        }

        return new DeltaModels(array_map($this->model(...), $bySlot), $this->model($all));
    }

    /**
     * @param non-empty-list<array{float, float, string}> $points température extérieure, écart, instant
     */
    private function model(array $points): DeltaModel
    {
        $n = \count($points);
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
        }

        $weight = $sxx / ($sxx + self::PRIOR_STRENGTH);
        $slope = max(self::SLOPE_MIN, min(self::SLOPE_MAX, ($sxy + self::PRIOR_STRENGTH * self::TYPICAL_SLOPE) / ($sxx + self::PRIOR_STRENGTH)));

        return new DeltaModel(
            $weight >= self::REGRESSION_WEIGHT ? ModelKind::Regression : ModelKind::Typical,
            $meanY - $slope * $meanX,
            $slope,
            $n,
            \count(array_unique(array_column($points, 2))),
            min($xs),
            max($xs),
            $weight,
        );
    }
}
