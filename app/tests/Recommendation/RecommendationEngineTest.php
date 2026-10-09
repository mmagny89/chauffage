<?php

declare(strict_types=1);

namespace App\Tests\Recommendation;

use App\Calculation\DeltaCalculator;
use App\Calculation\DeltaReport;
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
        // dehors 10,0 + écart 13,0 = 23,0 ≥ 19
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), 10.0);

        self::assertSame(HeatingAction::Cut, $result->action);
        self::assertSame(23.0, $result->estimatedIndoor);
        self::assertNull($result->setpoint());
    }

    public function testExactlyOnTargetCuts(): void
    {
        // dehors 6,0 + écart 13,0 = 19,0 = cible : pas strictement en dessous.
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), 6.0);

        self::assertSame(HeatingAction::Cut, $result->action);
    }

    public function testOneTenthBelowTargetHeats(): void
    {
        $result = $this->morning($this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]), 5.9);

        self::assertSame(HeatingAction::Heat, $result->action);
        self::assertSame(18.9, $result->estimatedIndoor);
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
        self::assertSame(10.0, $day->forSlot(DaySlot::Evening)->estimatedIndoor, 'Écart du soir (+5), et non celui du matin.');
    }

    public function testFallsBackToTheOverallDeltaWhenTheSlotHasNoReading(): void
    {
        $deltas = $this->deltas(['2026-10-08 08:00' => [5.0, 18.0]]);

        $night = $this->engine->recommend([$this->forecastDay(['night' => 4.0])], $deltas, self::week())[0]->forSlot(DaySlot::Night);

        self::assertTrue($night->deltaIsFallback);
        self::assertSame(13.0, $night->delta);
        self::assertSame(17.0, $night->estimatedIndoor);
        self::assertSame(HeatingAction::Cut, $night->action, '17,0 ≥ 17');
    }

    public function testUnknownWithoutAnyReading(): void
    {
        $empty = (new DeltaCalculator())->calculate([]);

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
        // Véranda : écart −4,0 ; dehors −2,0 → dedans −6,0, très sous la cible.
        $deltas = $this->deltas(['2026-01-10 08:00' => [34.0, 30.0]]);

        $result = $this->morning($deltas, -2.0);

        self::assertSame(-6.0, $result->estimatedIndoor);
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

    private function morning(DeltaReport $deltas, float $outdoor): \App\Recommendation\SlotRecommendation
    {
        return $this->engine->recommend([$this->forecastDay(['morning' => $outdoor])], $deltas, self::week())[0]->forSlot(DaySlot::Morning);
    }

    /**
     * @param array<string, array{float, float}> $readings heure => [extérieur, intérieur]
     */
    private function deltas(array $readings): DeltaReport
    {
        $place = new Place(new Household(new User()), 'Salon');

        return (new DeltaCalculator())->calculate(array_map(
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
