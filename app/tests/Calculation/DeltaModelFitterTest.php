<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\DeltaModel;
use App\Calculation\DeltaModelFitter;
use App\Calculation\ModelKind;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use PHPUnit\Framework\TestCase;

final class DeltaModelFitterTest extends TestCase
{
    private DeltaModelFitter $fitter;
    private Household $household;

    protected function setUp(): void
    {
        $this->fitter = new DeltaModelFitter();
        $this->household = new Household(new User());
    }

    public function testNoReadingGivesNoModel(): void
    {
        $models = $this->fitter->fit([]);

        self::assertTrue($models->isEmpty());
        self::assertNull($models->overall);
        self::assertNull($models->forSlot(DaySlot::Morning));
    }

    public function testRecoversAnExactLine(): void
    {
        // Intérieur = 8 + 0,6 × extérieur, donc écart = 8 − 0,4 × extérieur.
        $model = $this->morning($this->fitter->fit($this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t)));

        self::assertSame(ModelKind::Regression, $model->kind);
        self::assertEqualsWithDelta(-0.4, $model->slope, 1e-9);
        self::assertEqualsWithDelta(8.0, $model->intercept, 1e-9);
        self::assertSame(5, $model->readings);
        self::assertSame(5, $model->sessions);
        self::assertSame(2.0, $model->outdoorMin);
        self::assertSame(14.0, $model->outdoorMax);
        self::assertNull($model->refusal);
        self::assertEqualsWithDelta(6.4, $model->deltaAt(4.0), 1e-9);
        self::assertEqualsWithDelta(10.0, $model->deltaAt(-5.0), 1e-9, 'Plus froid : écart plus grand.');
    }

    public function testLinePassesThroughTheCentroid(): void
    {
        $readings = $this->morningReadings([1.0, 4.0, 6.0, 9.0, 12.0], static fn (float $t): float => 10.0 + 0.5 * $t + (0.0 === fmod($t, 2.0) ? 0.3 : -0.2));

        $model = $this->morning($this->fitter->fit($readings));

        $meanX = array_sum(array_map(static fn (Reading $r) => $r->getOutdoorTemperature(), $readings)) / 5;
        $meanY = array_sum(array_map(static fn (Reading $r) => $r->getDelta(), $readings)) / 5;
        self::assertEqualsWithDelta($meanY, $model->deltaAt($meanX), 1e-9);
    }

    public function testTooFewSessionsFallBackToTheMean(): void
    {
        $model = $this->morning($this->fitter->fit($this->morningReadings([2.0, 8.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t)));

        self::assertSame(ModelKind::Mean, $model->kind);
        self::assertSame(DeltaModel::REFUSAL_FEW_SESSIONS, $model->refusal);
        self::assertSame(0.0, $model->slope);
        self::assertEqualsWithDelta((7.2 + 4.8 + 2.4) / 3, $model->intercept, 1e-9);
        self::assertEqualsWithDelta($model->intercept, $model->deltaAt(-10.0), 1e-9, 'Constant, quelle que soit la température.');
    }

    public function testSessionsAreDistinctInstantsNotReadings(): void
    {
        // Trois instants, deux lieux chacun : six relevés mais seulement trois instants.
        $salon = new Place($this->household, 'Salon');
        $cave = new Place($this->household, 'Cave');
        $readings = [];
        foreach ([[2.0, '2026-10-05'], [8.0, '2026-10-06'], [14.0, '2026-10-07']] as [$t, $day]) {
            $readings[] = new Reading($salon, new \DateTimeImmutable($day.' 08:00'), $t, $t + 8.0);
            $readings[] = new Reading($cave, new \DateTimeImmutable($day.' 08:00'), $t, $t + 3.0);
        }

        $model = $this->morning($this->fitter->fit($readings));

        self::assertSame(6, $model->readings);
        self::assertSame(3, $model->sessions);
        self::assertSame(ModelKind::Mean, $model->kind);
    }

    public function testNarrowOutdoorRangeFallsBackToTheMean(): void
    {
        $model = $this->morning($this->fitter->fit($this->morningReadings([5.0, 6.0, 6.5, 7.0], static fn (float $t): float => 18.0)));

        self::assertSame(ModelKind::Mean, $model->kind);
        self::assertSame(DeltaModel::REFUSAL_NARROW_RANGE, $model->refusal);
        self::assertSame(4, $model->sessions);
    }

    public function testExactlyTheMinimumSpreadIsEnough(): void
    {
        $model = $this->morning($this->fitter->fit($this->morningReadings([5.0, 6.0, 7.0, 8.0], static fn (float $t): float => 8.0 + 0.6 * $t)));

        self::assertSame(ModelKind::Regression, $model->kind, 'Quatre instants et exactement 3 °C d’étendue.');
    }

    public function testSlopeAboveZeroIsClampedAndTheMeanIsKept(): void
    {
        // L'intérieur monte plus vite que le dehors (soleil) : écart croissant avec la température, non physique pour de l'amorti.
        $readings = $this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 10.0 + 1.5 * $t);

        $model = $this->morning($this->fitter->fit($readings));

        self::assertSame(ModelKind::Regression, $model->kind);
        self::assertSame(0.0, $model->slope);
        $meanDelta = array_sum(array_map(static fn (Reading $r) => $r->getDelta(), $readings)) / 5;
        self::assertEqualsWithDelta($meanDelta, $model->intercept, 1e-9, 'Pente bornée à 0 : la droite horizontale passe par la moyenne.');
    }

    public function testSlopeBelowMinusOneIsClamped(): void
    {
        // Intérieur = 20 − 0,5 × extérieur : écart = 20 − 1,5 × extérieur (pente −1,5), ramenée à −1.
        $model = $this->morning($this->fitter->fit($this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 20.0 - 0.5 * $t)));

        self::assertSame(-1.0, $model->slope);
        // Indoor constant à la moyenne : intérieur estimé indépendant du dehors.
        self::assertEqualsWithDelta($model->deltaAt(3.0) + 3.0, $model->deltaAt(9.0) + 9.0, 1e-9);
    }

    public function testSlotsAreFittedIndependentlyAndOverallPoolsEverything(): void
    {
        $salon = new Place($this->household, 'Salon');
        $readings = $this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t);
        $readings[] = new Reading($salon, new \DateTimeImmutable('2026-10-05 20:00'), 9.0, 19.0);
        $readings[] = new Reading($salon, new \DateTimeImmutable('2026-10-06 20:00'), 10.0, 20.0);

        $models = $this->fitter->fit($readings);

        self::assertSame(ModelKind::Regression, $this->morning($models)->kind);
        $evening = $models->forSlot(DaySlot::Evening);
        self::assertNotNull($evening);
        self::assertSame(ModelKind::Mean, $evening->kind);
        self::assertSame(2, $evening->readings);
        self::assertNull($models->forSlot(DaySlot::Night));
        self::assertNotNull($models->overall);
        self::assertSame(7, $models->overall->readings);
        self::assertSame(7, $models->overall->sessions);
    }

    public function testResultDoesNotDependOnReadingOrder(): void
    {
        $readings = $this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t + (int) $t % 3 * 0.1);

        $forward = $this->fitter->fit($readings)->overall;
        $backward = $this->fitter->fit(array_reverse($readings))->overall;

        self::assertNotNull($forward);
        self::assertNotNull($backward);
        self::assertEqualsWithDelta($forward->slope, $backward->slope, 1e-9);
        self::assertEqualsWithDelta($forward->intercept, $backward->intercept, 1e-9);
    }

    public function testCoversUsesTheMeasuredRangeWithAMargin(): void
    {
        $model = $this->morning($this->fitter->fit($this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t)));

        self::assertTrue($model->covers(2.0));
        self::assertTrue($model->covers(-1.0), '3 °C sous le minimum : encore dans la marge.');
        self::assertFalse($model->covers(-1.1));
        self::assertTrue($model->covers(17.0));
        self::assertFalse($model->covers(17.1));
    }

    public function testNegativeOutdoorTemperatures(): void
    {
        $model = $this->morning($this->fitter->fit($this->morningReadings([-8.0, -5.0, -2.0, 1.0, 4.0], static fn (float $t): float => 12.0 + 0.5 * $t)));

        self::assertSame(ModelKind::Regression, $model->kind);
        self::assertEqualsWithDelta(-0.5, $model->slope, 1e-9);
        self::assertEqualsWithDelta(12.0, $model->intercept, 1e-9);
        self::assertEqualsWithDelta(16.0, $model->deltaAt(-8.0), 1e-9);
    }

    private function morning(\App\Calculation\DeltaModels $models): DeltaModel
    {
        return $models->forSlot(DaySlot::Morning) ?? throw new \LogicException('Pas de modèle pour le matin.');
    }

    /**
     * Un relevé du matin par jour, à ces températures extérieures.
     *
     * @param list<float>            $outdoors
     * @param callable(float): float $indoor
     *
     * @return list<Reading>
     */
    private function morningReadings(array $outdoors, callable $indoor): array
    {
        $place = new Place($this->household, 'Salon');
        $readings = [];
        foreach ($outdoors as $index => $outdoor) {
            $readings[] = new Reading($place, new \DateTimeImmutable(\sprintf('2026-10-%02d 08:00', $index + 1)), $outdoor, round($indoor($outdoor), 1));
        }

        return $readings;
    }
}
