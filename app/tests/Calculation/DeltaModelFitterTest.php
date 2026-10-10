<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\DeltaModel;
use App\Calculation\DeltaModelFitter;
use App\Calculation\DeltaModels;
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

    public function testSingleReadingKeepsItsPointAndUsesTheTypicalSlope(): void
    {
        // 8 °C dehors, 17,3 °C dedans : écart +9,3.
        $model = $this->morning($this->fitter->fit($this->morningReadings([8.0], static fn (): float => 17.3)));

        self::assertSame(ModelKind::Typical, $model->kind);
        self::assertSame(DeltaModelFitter::TYPICAL_SLOPE, $model->slope);
        self::assertSame(0.0, $model->dataWeight);
        self::assertEqualsWithDelta(9.3, $model->deltaAt(8.0), 1e-9, 'La droite passe par la mesure.');
        self::assertSame(1, $model->readings);
        self::assertSame(1, $model->sessions);
    }

    public function testAHouseholdSlopeReplacesTheTypicalOne(): void
    {
        $place = new Place($this->household, 'Salon');
        $readings = [new Reading($place, new \DateTimeImmutable('2026-10-08 08:00'), 8.0, 17.3)];

        self::assertSame(-0.7, $this->fitter->fit($readings, -0.7)->overall?->slope);
        self::assertSame(-0.7, $this->fitter->fitPlaces($readings, -0.7)['salon']->overall?->slope);
        self::assertSame(DeltaModelFitter::TYPICAL_SLOPE, $this->fitter->fit($readings)->overall?->slope);
        self::assertSame(DeltaModelFitter::SLOPE_MIN, $this->fitter->fit($readings, -3.0)->overall?->slope, 'Bornée comme toute pente.');
    }

    public function testAMildForecastNoLongerInheritsAColdWeatherGap(): void
    {
        // Le cas signalé : un seul relevé à 8 °C (écart +9,3), prévision de 21 °C.
        // Un écart constant donnait 30,3 °C dans le salon ; l'écart doit se réduire quand il fait doux.
        $model = $this->morning($this->fitter->fit($this->morningReadings([8.0], static fn (): float => 17.3)));

        $indoor = 21.0 + $model->deltaAt(21.0);

        self::assertEqualsWithDelta(25.1, $indoor, 1e-9, '9,3 − 0,4 × 13 = 4,1 d’écart.');
        self::assertLessThan(30.3, $indoor);
        self::assertGreaterThan(21.0, $indoor, 'Un peu plus chaud que le dehors, pas 9 degrés de plus.');
        self::assertFalse($model->covers(21.0), 'Et la prévision est signalée hors plage mesurée.');
    }

    public function testSlopeIsThePenalisedLeastSquaresFormula(): void
    {
        // Intérieur = 8 + 0,8 × extérieur : écart = 8 − 0,2 × extérieur.
        $outdoors = [0.0, 2.0, 4.0, 6.0, 8.0, 10.0, 12.0, 14.0];
        $model = $this->morning($this->fitter->fit($this->morningReadings($outdoors, static fn (float $t): float => 8.0 + 0.8 * $t)));

        $sxx = 168.0; // Σ(x − 7)²
        $sxy = -0.2 * $sxx;
        $lambda = DeltaModelFitter::PRIOR_STRENGTH;
        $expected = ($sxy + $lambda * DeltaModelFitter::TYPICAL_SLOPE) / ($sxx + $lambda);

        self::assertEqualsWithDelta($expected, $model->slope, 1e-9);
        self::assertEqualsWithDelta($sxx / ($sxx + $lambda), $model->dataWeight, 1e-9);
        self::assertSame(ModelKind::Regression, $model->kind);
        self::assertEqualsWithDelta(-0.2, $model->slope, 0.05, 'Des relevés nombreux et variés l’emportent sur la pente typique.');
    }

    public function testRecoversTheLineWhenTheDataSlopeEqualsTheTypicalSlope(): void
    {
        // Intérieur = 8 + 0,6 × extérieur : écart = 8 − 0,4 × extérieur, c'est la pente typique.
        $model = $this->morning($this->fitter->fit($this->morningReadings([2.0, 5.0, 8.0, 11.0, 14.0], static fn (float $t): float => 8.0 + 0.6 * $t)));

        self::assertEqualsWithDelta(-0.4, $model->slope, 1e-9);
        self::assertEqualsWithDelta(8.0, $model->intercept, 1e-9);
        self::assertEqualsWithDelta(6.4, $model->deltaAt(4.0), 1e-9);
        self::assertEqualsWithDelta(10.0, $model->deltaAt(-5.0), 1e-9, 'Plus froid : écart plus grand.');
        self::assertSame(ModelKind::Regression, $model->kind);
        self::assertEqualsWithDelta(90.0 / 115.0, $model->dataWeight, 1e-9);
        self::assertSame(2.0, $model->outdoorMin);
        self::assertSame(14.0, $model->outdoorMax);
    }

    public function testFewCloseReadingsStayTypical(): void
    {
        // 5 à 8 °C : Sxx = 5, la pente reste proche de la pente typique même si les données disent −0,1.
        $model = $this->morning($this->fitter->fit($this->morningReadings([5.0, 6.0, 7.0, 8.0], static fn (float $t): float => 15.0 + 0.9 * $t)));

        self::assertSame(ModelKind::Typical, $model->kind);
        self::assertEqualsWithDelta((-0.1 * 5.0 + 25.0 * -0.4) / 30.0, $model->slope, 1e-9);
        self::assertLessThan(0.2, $model->dataWeight);
    }

    public function testDataWeightGrowsWithTheVarietyOfTemperatures(): void
    {
        $weight = fn (float ...$outdoors): float => $this->morning($this->fitter->fit($this->morningReadings(array_values($outdoors), static fn (float $t): float => 8.0 + 0.6 * $t)))->dataWeight;

        self::assertLessThan($weight(6.0, 7.0, 8.0, 9.0, 10.0), $weight(7.5, 8.0, 8.5));
        self::assertLessThan($weight(2.0, 5.0, 8.0, 11.0, 14.0), $weight(6.0, 7.0, 8.0, 9.0, 10.0));
        self::assertLessThan($weight(2.0, 5.0, 8.0, 11.0, 14.0, 17.0, 20.0), $weight(2.0, 5.0, 8.0, 11.0, 14.0));
        self::assertSame(0.0, $weight(8.0, 8.0, 8.0), 'Aucune variété : la pente est la pente typique.');
    }

    public function testLinePassesThroughTheCentroid(): void
    {
        $readings = $this->morningReadings([1.0, 4.0, 6.0, 9.0, 12.0], static fn (float $t): float => 10.0 + 0.5 * $t + (0.0 === fmod($t, 2.0) ? 0.3 : -0.2));

        $model = $this->morning($this->fitter->fit($readings));

        $meanX = array_sum(array_map(static fn (Reading $r) => $r->getOutdoorTemperature(), $readings)) / 5;
        $meanY = array_sum(array_map(static fn (Reading $r) => $r->getDelta(), $readings)) / 5;
        self::assertEqualsWithDelta($meanY, $model->deltaAt($meanX), 1e-9);
    }

    public function testSlopeIsClampedToPlausibleBounds(): void
    {
        $outdoors = [0.0, 2.0, 4.0, 6.0, 8.0, 10.0, 12.0, 14.0];

        // L'intérieur monte bien plus vite que le dehors : écart croissant, non physique pour de l'amorti.
        $rising = $this->morning($this->fitter->fit($this->morningReadings($outdoors, static fn (float $t): float => 10.0 + 1.5 * $t)));
        // L'intérieur baisse quand le dehors monte : écart en −1,5 × extérieur.
        $falling = $this->morning($this->fitter->fit($this->morningReadings($outdoors, static fn (float $t): float => 20.0 - 0.5 * $t)));

        self::assertSame(DeltaModelFitter::SLOPE_MAX, $rising->slope);
        self::assertSame(DeltaModelFitter::SLOPE_MIN, $falling->slope);
    }

    public function testFitPlacesGivesEachPlaceItsOwnModelIgnoringCase(): void
    {
        $salon = new Place($this->household, 'Salon');
        $cave = new Place($this->household, 'Cave');
        $readings = [
            new Reading($salon, new \DateTimeImmutable('2026-10-08 08:00'), 5.0, 15.0),   // écart +10
            new Reading($cave, new \DateTimeImmutable('2026-10-08 08:00'), 5.0, 8.0),     // écart +3
            new Reading($salon, new \DateTimeImmutable('2026-10-09 08:00'), 6.0, 16.0),   // écart +10
        ];

        $models = $this->fitter->fitPlaces($readings);

        self::assertSame(['salon', 'cave'], array_keys($models));
        self::assertEqualsWithDelta(10.0, $models['salon']->overall?->deltaAt(5.5) ?? 0.0, 0.3);
        self::assertSame(2, $models['salon']->overall?->readings);
        self::assertEqualsWithDelta(3.0, $models['cave']->overall?->deltaAt(5.0) ?? 0.0, 1e-9);
        self::assertSame(1, $models['cave']->overall?->readings);
        self::assertSame([], $this->fitter->fitPlaces([]));
    }

    public function testSessionsAreDistinctInstantsNotReadings(): void
    {
        // Trois instants, deux lieux chacun : six relevés mais trois instants.
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
        self::assertSame(ModelKind::Typical, $evening->kind);
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

    public function testNegativeOutdoorTemperaturesAndFrostEstimates(): void
    {
        // Intérieur = 12 + 0,5 × extérieur (écart 12 − 0,5 × extérieur), relevés de −8 à 4 °C.
        $model = $this->morning($this->fitter->fit($this->morningReadings([-8.0, -5.0, -2.0, 1.0, 4.0], static fn (float $t): float => 12.0 + 0.5 * $t)));

        self::assertLessThan(0.0, $model->slope);
        self::assertGreaterThanOrEqual(DeltaModelFitter::SLOPE_MIN, $model->slope);
        self::assertLessThanOrEqual(DeltaModelFitter::SLOPE_MAX, $model->slope);
        // Par −8 °C, l'écart relevé est de 16 : le modèle le retrouve (le point est dans les mesures).
        self::assertEqualsWithDelta(16.0, $model->deltaAt(-8.0), 0.9);
        self::assertGreaterThan($model->deltaAt(4.0), $model->deltaAt(-8.0), 'Plus froid, écart plus grand.');
    }

    private function morning(DeltaModels $models): DeltaModel
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
