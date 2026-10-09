<?php

declare(strict_types=1);

namespace App\Calculation;

use App\Entity\Reading;

/**
 * Ajuste, par créneau, l'écart intérieur − extérieur en fonction de la température
 * extérieure : en l'absence de chauffage, l'intérieur suit le dehors de façon amortie
 * (pente intérieur/extérieur inférieure à 1), donc l'écart grandit quand il fait plus froid.
 *
 * Moindres carrés ordinaires, avec trois garde-fous : au moins MIN_SESSIONS relevés à des
 * instants distincts, une étendue d'au moins MIN_SPREAD °C de températures extérieures
 * (sinon la pente n'est pas déterminée), et une pente de l'écart bornée à [-1 ; 0] — hors
 * de cette plage, l'intérieur ne suivrait pas le dehors de façon amortie et le résultat
 * serait du bruit. La droite passe toujours par le point moyen des mesures. Sans les
 * garde-fous, repli sur l'écart moyen constant. Calcul pur.
 */
final class DeltaModelFitter
{
    public const MIN_SESSIONS = 4;
    public const MIN_SPREAD = 3.0;
    public const SLOPE_MIN = -1.0;
    public const SLOPE_MAX = 0.0;

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
        $min = min($xs);
        $max = max($xs);
        $sessions = \count(array_unique(array_column($points, 2)));

        $mean = static fn (?string $refusal): DeltaModel => new DeltaModel(ModelKind::Mean, $meanY, 0.0, $n, $sessions, $min, $max, $refusal);

        if ($sessions < self::MIN_SESSIONS) {
            return $mean(DeltaModel::REFUSAL_FEW_SESSIONS);
        }
        if ($max - $min < self::MIN_SPREAD) {
            return $mean(DeltaModel::REFUSAL_NARROW_RANGE);
        }

        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
        }
        $slope = max(self::SLOPE_MIN, min(self::SLOPE_MAX, $sxy / $sxx));

        return new DeltaModel(ModelKind::Regression, $meanY - $slope * $meanX, $slope, $n, $sessions, $min, $max);
    }
}
