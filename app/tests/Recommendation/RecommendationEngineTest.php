<?php

declare(strict_types=1);

namespace App\Tests\Recommendation;

use App\Calculation\DeltaModelFitter;
use App\Calculation\DeltaModels;
use App\Calculation\ModelKind;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Forecast\DayForecast;
use App\Forecast\SlotForecast;
use App\Recommendation\HeatingAction;
use App\Recommendation\RecommendationEngine;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineTest extends TestCase
{
    private const TARGETS = ['night' => 17.0, 'morning' => 19.0, 'afternoon' => 19.0, 'evening' => 20.0];

    /**
     * @param array<string, float> $perSlot
     *
     * @return array<int, array<string, float>>
     */
    private static function week(array $perSlot = self::TARGETS): array
    {
        return array_fill_keys(array_map(static fn (Weekday $d): int => $d->value, Weekday::cases()), $perSlot);
    }

    private RecommendationEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RecommendationEngine();
    }

    public function testHeatsWhenEstimatedIndoorIsBelowTarget(): void
    {
        // dehors 5,0 + écart 13,0 = 18,0 < 19 (matin)
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), 5.0);

        self::assertSame(HeatingAction::Heat, $result->action);
        self::assertSame(18.0, $result->estimatedIndoor);
        self::assertSame(13.0, $result->delta);
        self::assertSame(19.0, $result->setpoint(), 'Consigne = température visée.');
    }

    public function testCutsWhenEstimatedIndoorIsAboveTarget(): void
    {
        // Relevé à 5 °C : écart +13. Par 10 °C, l'écart se réduit de 0,4 × 5 = 2 : +11, soit 21,0 ≥ 19.
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), 10.0);

        self::assertSame(HeatingAction::Cut, $result->action);
        self::assertSame(11.0, $result->delta);
        self::assertSame(21.0, $result->estimatedIndoor);
        self::assertNull($result->setpoint());
    }

    public function testExactlyOnTargetCuts(): void
    {
        // Au point du relevé (5 °C) l'estimation vaut 18,0 : égale à la cible, pas strictement en dessous.
        $targets = self::week(['night' => 17.0, 'morning' => 18.0, 'afternoon' => 19.0, 'evening' => 20.0]);
        $result = $this->engine->recommend([$this->forecastDay(['morning' => 5.0])], $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), $targets)[0]->forSlot(DaySlot::Morning);

        self::assertSame(18.0, $result->estimatedIndoor);
        self::assertSame(HeatingAction::Cut, $result->action);
    }

    public function testOneTenthBelowTargetHeats(): void
    {
        $targets = self::week(['night' => 17.0, 'morning' => 18.1, 'afternoon' => 19.0, 'evening' => 20.0]);
        $result = $this->engine->recommend([$this->forecastDay(['morning' => 5.0])], $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), $targets)[0]->forSlot(DaySlot::Morning);

        self::assertSame(HeatingAction::Heat, $result->action);
        self::assertSame(18.0, $result->estimatedIndoor);
    }

    public function testUsesTheDeltaOfTheSameSlot(): void
    {
        $deltas = $this->deltas([
            '2026-10-08 08:00' => [5.0, 18.0],  // matin  +13
            '2026-10-08 20:00' => [5.0, 10.0],  // soirée  +5
        ]);

        $day = $this->engine->recommend([$this->forecastDay(['morning' => 5.0, 'evening' => 5.0])], $deltas, self::week())[0];

        self::assertSame(13.0, $day->forSlot(DaySlot::Morning)->delta);
        self::assertSame(5.0, $day->forSlot(DaySlot::Evening)->delta);
        self::assertSame(18.0, $day->forSlot(DaySlot::Morning)->estimatedIndoor);
        self::assertSame(HeatingAction::Heat, $day->forSlot(DaySlot::Morning)->action, '18,0 < 19 : on chauffe');
        self::assertSame(10.0, $day->forSlot(DaySlot::Evening)->estimatedIndoor, 'Écart du soir (+5, à 5 °C), et non celui du matin.');
    }

    public function testFallsBackToTheOverallDeltaWhenTheSlotHasNoReading(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);

        $night = $this->engine->recommend([$this->forecastDay(['night' => 4.0])], $deltas, self::week())[0]->forSlot(DaySlot::Night);

        self::assertTrue($night->deltaIsFallback);
        self::assertSame(13.4, $night->delta, 'Par 4 °C, 1 °C sous le relevé : l’écart grandit de 0,4.');
        self::assertSame(17.4, $night->estimatedIndoor);
        self::assertSame(HeatingAction::Cut, $night->action, '17,4 ≥ 17');
    }

    public function testUnknownWithoutAnyReading(): void
    {
        $empty = (new DeltaModelFitter())->fit([]);

        $result = $this->morning($empty, 5.0);

        self::assertSame(HeatingAction::Unknown, $result->action);
        self::assertNull($result->estimatedIndoor);
        self::assertNull($result->setpoint());
    }

    public function testUnknownWithoutForecastForTheSlot(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);

        $day = $this->engine->recommend([$this->forecastDay([])], $deltas, self::week())[0];

        foreach (DaySlot::cases() as $slot) {
            self::assertSame(HeatingAction::Unknown, $day->forSlot($slot)->action);
        }
    }

    public function testTargetsDifferPerSlot(): void
    {
        // Même température estimée de 18,5 : sous 19 (matin), au-dessus de 17 (nuit).
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.5]]); // écart +13,5 partout (repli)

        $day = $this->engine->recommend([$this->forecastDay(['morning' => 5.0, 'night' => 5.0])], $deltas, self::week())[0];

        self::assertSame(HeatingAction::Heat, $day->forSlot(DaySlot::Morning)->action);
        self::assertSame(HeatingAction::Cut, $day->forSlot(DaySlot::Night)->action);
    }

    public function testNegativeDeltaAndFreezingOutdoor(): void
    {
        // Véranda relevée en canicule : écart −4,0 à 34 °C. Par −2 °C, 36 °C plus froid, l'écart
        // grandit de 0,4 × 36 = 14,4 : +10,4, soit 8,4 dedans, très sous la cible.
        $deltas = $this->deltas(['2026-01-10 08:00' => [34.0, 30.0]]);

        $result = $this->morning($deltas, -2.0);

        self::assertSame(10.4, $result->delta);
        self::assertSame(8.4, $result->estimatedIndoor);
        self::assertSame(HeatingAction::Heat, $result->action);
    }

    public function testIncompleteForecastIsCarriedOver(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);
        $day = new DayForecast(new \DateTimeImmutable('2026-10-09'), $this->slots(['night' => 4.0], incomplete: ['night']));

        $night = $this->engine->recommend([$day], $deltas, self::week())[0]->forSlot(DaySlot::Night);

        self::assertFalse($night->forecastComplete);
    }

    public function testTargetDependsOnTheWeekday(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]); // +13
        $targets = self::week();
        $targets[Weekday::Saturday->value]['morning'] = 17.0; // week-end : plus bas

        // Vendredi 9 et samedi 10 octobre 2026 : dehors 5,0 → dedans estimé 18,0.
        $friday = new DayForecast(new \DateTimeImmutable('2026-10-09'), $this->slots(['morning' => 5.0]));
        $saturday = new DayForecast(new \DateTimeImmutable('2026-10-10'), $this->slots(['morning' => 5.0]));
        [$fri, $sat] = $this->engine->recommend([$friday, $saturday], $deltas, $targets);

        self::assertSame(HeatingAction::Heat, $fri->forSlot(DaySlot::Morning)->action, '18,0 < 19 le vendredi');
        self::assertSame(19.0, $fri->forSlot(DaySlot::Morning)->target);
        self::assertSame(HeatingAction::Cut, $sat->forSlot(DaySlot::Morning)->action, '18,0 ≥ 17 le samedi');
        self::assertSame(17.0, $sat->forSlot(DaySlot::Morning)->target);
    }

    public function testRegressionMakesTheDeltaGrowWhenItGetsColder(): void
    {
        // Relevés par temps doux (2 à 14 °C) : intérieur = 8 + 0,6 × extérieur.
        $models = $this->regressionModels();

        // Prévision de 4,0 °C : écart 8 − 0,4 × 4 = 6,4 → intérieur 10,4 (et non 8,8 avec l'écart moyen de 4,8).
        $result = $this->engine->recommend([$this->forecastDay(['morning' => 4.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);

        self::assertSame(ModelKind::Regression, $result->method);
        self::assertSame(6.4, $result->delta);
        self::assertSame(10.4, $result->estimatedIndoor);
        self::assertFalse($result->extrapolated);
        self::assertSame(HeatingAction::Heat, $result->action);
    }

    public function testRegressionAndMeanDisagreeWhereItMatters(): void
    {
        $models = $this->regressionModels();
        $cold = $this->engine->recommend([$this->forecastDay(['morning' => 4.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);
        $mild = $this->engine->recommend([$this->forecastDay(['morning' => 14.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);

        // Au point moyen des mesures (8 °C), la droite vaut l'écart moyen (4,8) ; ailleurs elle s'en écarte.
        self::assertSame(2.4, $mild->delta);
        self::assertSame(16.4, $mild->estimatedIndoor);
        self::assertGreaterThan($mild->delta, $cold->delta);
    }

    public function testForecastOutsideTheMeasuredRangeIsFlagged(): void
    {
        $models = $this->regressionModels();

        $inside = $this->engine->recommend([$this->forecastDay(['morning' => 16.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);
        $below = $this->engine->recommend([$this->forecastDay(['morning' => -5.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);
        $above = $this->engine->recommend([$this->forecastDay(['morning' => 18.0])], $models, self::week())[0]->forSlot(DaySlot::Morning);

        self::assertFalse($inside->extrapolated, '16 °C : dans la marge de 3 °C au-dessus de 14.');
        self::assertTrue($below->extrapolated);
        self::assertTrue($above->extrapolated);
    }

    public function testTypicalSlopeIsUsedWithoutEnoughReadingsAndSaysSo(): void
    {
        $models = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);

        $result = $this->morning($models, 5.0);

        self::assertSame(ModelKind::Typical, $result->method);
        self::assertSame(13.0, $result->delta, 'Au point du relevé, l’écart mesuré.');
        self::assertTrue($this->morning($models, 25.0)->extrapolated);
    }

    public function testAnEstimateStaysRealisticFarFromTheMeasuredTemperature(): void
    {
        // Un relevé à 8 °C, écart +9,3 ; prévision de 21 °C : l'écart se réduit, l'intérieur ne dépasse pas 26 °C.
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [8.0, 17.3]]), 21.0);

        self::assertSame(4.1, $result->delta);
        self::assertSame(25.1, $result->estimatedIndoor);
        self::assertTrue($result->extrapolated);
    }

    public function testOverallRegressionIsTheFallbackForASlotWithoutReadings(): void
    {
        $models = $this->regressionModels(); // relevés du matin seulement

        $evening = $this->engine->recommend([$this->forecastDay(['evening' => 4.0])], $models, self::week())[0]->forSlot(DaySlot::Evening);

        self::assertTrue($evening->deltaIsFallback);
        self::assertSame(ModelKind::Regression, $evening->method);
        self::assertSame(6.4, $evening->delta);
    }

    public function testUpcomingStartsWithTheRunningSlot(): void
    {
        $days = $this->recommendedDays(3);

        $upcoming = $this->engine->upcoming($days, new \DateTimeImmutable('2026-10-09 14:00'), 4);

        self::assertSame(
            ['2026-10-09 afternoon', '2026-10-09 evening', '2026-10-09 night', '2026-10-10 morning'],
            array_map(static fn ($u) => $u->date->format('Y-m-d').' '.$u->recommendation->slot->value, $upcoming),
        );
    }

    public function testUpcomingSkipsSlotsAlreadyOver(): void
    {
        $upcoming = $this->engine->upcoming($this->recommendedDays(3), new \DateTimeImmutable('2026-10-09 22:00'), 2);

        self::assertSame(
            ['2026-10-09 night', '2026-10-10 morning'],
            array_map(static fn ($u) => $u->date->format('Y-m-d').' '.$u->recommendation->slot->value, $upcoming),
            'À 22 h pile, la soirée est finie ; la nuit commence.',
        );
    }

    public function testUpcomingAtThreeInTheMorningSkipsThePreviousNight(): void
    {
        $upcoming = $this->engine->upcoming($this->recommendedDays(3), new \DateTimeImmutable('2026-10-09 03:00'), 1);

        self::assertSame('morning', $upcoming[0]->recommendation->slot->value);
        self::assertSame('2026-10-09', $upcoming[0]->date->format('Y-m-d'));
    }

    public function testUpcomingStopsWhenThePrevisionsRunOut(): void
    {
        $upcoming = $this->engine->upcoming($this->recommendedDays(1), new \DateTimeImmutable('2026-10-09 19:00'), 4);

        self::assertCount(2, $upcoming, 'Soirée et nuit du dernier jour.');
    }

    private function regressionModels(): DeltaModels
    {
        $readings = [];
        foreach ([2.0, 5.0, 8.0, 11.0, 14.0] as $index => $outdoor) {
            $readings[\sprintf('2026-10-%02d 08:00', $index + 1)] = [$outdoor, round(8.0 + 0.6 * $outdoor, 1)];
        }

        return $this->deltas($readings);
    }

    public function testHeatingAlreadyOnTodayMarksOnlyTodaysUpcomingSlots(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);
        $days = $this->engine->recommend([$this->forecastDay(['morning' => 5.0, 'afternoon' => 5.0])], $deltas, self::week());
        $now = new \DateTimeImmutable('2026-10-09 07:00');
        $heating = new \App\Calculation\HeatingDays(['2026-10-09' => ['salon']]);

        $plain = $this->engine->upcoming($days, $now, 2);
        self::assertFalse($plain[0]->recommendation->heatingOn);
        self::assertSame(19.0, $plain[0]->recommendation->setpoint());

        $marked = $this->engine->upcoming($days, $now, 2, $heating);
        self::assertTrue($marked[0]->recommendation->heatingOn, 'Chauffage allumé aujourd’hui : « Chauffer » seul.');
        self::assertSame(HeatingAction::Heat, $marked[0]->recommendation->action);
        self::assertSame(19.0, $days[0]->forSlot(DaySlot::Morning)->setpoint(), 'Le calcul lui-même ne change pas.');
        self::assertFalse($days[0]->forSlot(DaySlot::Morning)->heatingOn);

        self::assertTrue($this->engine->upcoming($days, $now, 1, $heating, 'Salon')[0]->recommendation->heatingOn);
        self::assertFalse($this->engine->upcoming($days, $now, 1, $heating, 'Cave')[0]->recommendation->heatingOn, 'Un autre lieu n’est pas concerné.');

        $tomorrow = new \App\Calculation\HeatingDays(['2026-10-08' => ['salon']]);
        self::assertFalse($this->engine->upcoming($days, $now, 1, $tomorrow)[0]->recommendation->heatingOn, 'Allumage d’un autre jour : sans effet.');
    }

    private function morning(DeltaModels $deltas, float $outdoor): \App\Recommendation\SlotRecommendation
    {
        return $this->engine->recommend([$this->forecastDay(['morning' => $outdoor])], $deltas, self::week())[0]->forSlot(DaySlot::Morning);
    }

    /**
     * @param array<string, array{float, float}> $readings heure => [extérieur, intérieur]
     */
    private function deltas(array $readings): DeltaModels
    {
        $place = new Place(new Household(new User()), 'Salon');

        return (new DeltaModelFitter())->fit(array_map(
            static fn (string $at, array $temps): Reading => new Reading($place, new \DateTimeImmutable($at), $temps[0], $temps[1]),
            array_keys($readings),
            $readings,
        ));
    }

    /**
     * @param array<string, float> $averages moyenne par créneau ; un créneau absent n'a pas de prévision
     */
    private function forecastDay(array $averages): DayForecast
    {
        return new DayForecast(new \DateTimeImmutable('2026-10-09'), $this->slots($averages));
    }

    /**
     * @param array<string, float> $averages
     * @param list<string>         $incomplete
     *
     * @return array<string, SlotForecast>
     */
    private function slots(array $averages, array $incomplete = []): array
    {
        $expected = ['night' => 8, 'morning' => 6, 'afternoon' => 6, 'evening' => 4];
        $slots = [];
        foreach (DaySlot::cases() as $slot) {
            $average = $averages[$slot->value] ?? null;
            $hours = null === $average ? 0 : (\in_array($slot->value, $incomplete, true) ? 2 : $expected[$slot->value]);
            $slots[$slot->value] = new SlotForecast($slot, $average, $average, $average, $hours, $expected[$slot->value]);
        }

        return $slots;
    }

    /**
     * @return list<\App\Recommendation\DayRecommendation>
     */
    private function recommendedDays(int $count): array
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);
        $days = [];
        for ($i = 0; $i < $count; ++$i) {
            $date = (new \DateTimeImmutable('2026-10-09'))->modify(\sprintf('+%d days', $i));
            $days[] = new DayForecast($date, $this->slots(['night' => 5.0, 'morning' => 5.0, 'afternoon' => 5.0, 'evening' => 5.0]));
        }

        return $this->engine->recommend($days, $deltas, self::week());
    }
}
