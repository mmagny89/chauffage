<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\DeltaCalculator;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use PHPUnit\Framework\TestCase;

final class DeltaCalculatorTest extends TestCase
{
    private DeltaCalculator $calculator;
    private Household $household;

    protected function setUp(): void
    {
        $this->calculator = new DeltaCalculator();
        $this->household = new Household(new User());
    }

    public function testNoReadingGivesAnEmptyReport(): void
    {
        $report = $this->calculator->calculate([]);

        self::assertTrue($report->isEmpty());
        self::assertSame([], $report->places);
        self::assertNull($report->household->overall->average);
        self::assertSame(0, $report->household->overall->count);
    }

    public function testSingleReadingIsItsOwnAverage(): void
    {
        $salon = $this->place('Salon');

        $report = $this->calculator->calculate([$this->reading($salon, '2026-10-08 07:30', 4.5, 18.0)]);

        self::assertFalse($report->isEmpty());
        self::assertCount(1, $report->places);
        self::assertSame(13.5, $report->places[0]->overall->average);
        self::assertSame(1, $report->places[0]->overall->count);
        self::assertSame(13.5, $report->places[0]->forSlot(DaySlot::Morning)->average);
    }

    public function testAveragesPerPlaceAndPerSlot(): void
    {
        $salon = $this->place('Salon');

        $report = $this->calculator->calculate([
            $this->reading($salon, '2026-10-07 07:00', 5.0, 18.0),  // matin : +13,0
            $this->reading($salon, '2026-10-08 08:00', 3.0, 18.0),  // matin : +15,0
            $this->reading($salon, '2026-10-08 19:00', 10.0, 20.0), // soirée : +10,0
        ]);

        $place = $report->places[0];
        self::assertSame(14.0, $place->forSlot(DaySlot::Morning)->average);
        self::assertSame(2, $place->forSlot(DaySlot::Morning)->count);
        self::assertSame(10.0, $place->forSlot(DaySlot::Evening)->average);
        self::assertSame(12.7, $place->overall->average, '(13 + 15 + 10) / 3 = 12,67 arrondi à 12,7');
        self::assertSame(3, $place->overall->count);
    }

    public function testSlotWithoutReadingHasNoAverage(): void
    {
        $report = $this->calculator->calculate([$this->reading($this->place('Salon'), '2026-10-08 07:30', 5.0, 18.0)]);

        $night = $report->places[0]->forSlot(DaySlot::Night);

        self::assertNull($night->average);
        self::assertSame(0, $night->count);
    }

    public function testPlacesAreSeparatedAndSortedByName(): void
    {
        $cave = $this->place('cave');
        $salon = $this->place('Salon');
        $ecurie = $this->place('Écurie');

        $report = $this->calculator->calculate([
            $this->reading($salon, '2026-10-08 07:30', 5.0, 18.0),
            $this->reading($cave, '2026-10-08 07:30', 5.0, 12.0),
            $this->reading($ecurie, '2026-10-08 07:30', 5.0, 9.0),
        ]);

        self::assertSame(['cave', 'Écurie', 'Salon'], array_map(static fn ($p) => $p->placeName, $report->places));
        self::assertSame(7.0, $report->places[0]->overall->average);
        self::assertSame(13.0, $report->places[2]->overall->average);
    }

    public function testHouseholdWeighsEveryReadingEqually(): void
    {
        $salon = $this->place('Salon');
        $cave = $this->place('Cave');

        $report = $this->calculator->calculate([
            $this->reading($salon, '2026-10-07 07:00', 5.0, 20.0), // +15
            $this->reading($salon, '2026-10-08 07:00', 5.0, 20.0), // +15
            $this->reading($cave, '2026-10-08 07:00', 5.0, 8.0),   // +3
        ]);

        self::assertSame(11.0, $report->household->overall->average, '(15 + 15 + 3) / 3, et non la moyenne des moyennes de lieu');
        self::assertSame(3, $report->household->overall->count);
        self::assertSame(11.0, $report->household->forSlot(DaySlot::Morning)->average);
    }

    public function testNegativeDeltaKeepsItsSign(): void
    {
        $report = $this->calculator->calculate([$this->reading($this->place('Véranda'), '2026-07-10 14:00', 34.0, 30.0)]);

        self::assertSame(-4.0, $report->places[0]->overall->average);
    }

    public function testAverageRoundsHalfAwayFromZero(): void
    {
        $salon = $this->place('Salon');

        // (+13,0 + +13,1) / 2 = +13,05 → +13,1 ; (−0,1 + 0,0) / 2 = −0,05 → −0,1
        $positive = $this->calculator->calculate([
            $this->reading($salon, '2026-10-07 07:00', 5.0, 18.0),
            $this->reading($salon, '2026-10-08 07:00', 5.0, 18.1),
        ]);
        $negative = $this->calculator->calculate([
            $this->reading($salon, '2026-10-07 14:00', 20.1, 20.0),
            $this->reading($salon, '2026-10-08 14:00', 20.0, 20.0),
        ]);

        self::assertSame(13.1, $positive->places[0]->overall->average);
        self::assertSame(-0.1, $negative->places[0]->overall->average);
    }

    public function testResultDoesNotDependOnReadingOrder(): void
    {
        $salon = $this->place('Salon');
        $readings = [
            $this->reading($salon, '2026-10-07 07:00', 5.1, 18.3),
            $this->reading($salon, '2026-10-08 08:00', 3.7, 17.9),
            $this->reading($salon, '2026-10-09 09:00', 7.2, 19.4),
        ];

        $forward = $this->calculator->calculate($readings);
        $backward = $this->calculator->calculate(array_reverse($readings));

        self::assertEquals($forward, $backward);
    }

    public function testAcceptsAnyIterable(): void
    {
        $generator = (function (): \Generator {
            yield $this->reading($this->place('Salon'), '2026-10-08 07:30', 5.0, 18.0);
        })();

        self::assertSame(13.0, $this->calculator->calculate($generator)->places[0]->overall->average);
    }

    private function place(string $name): Place
    {
        return new Place($this->household, $name);
    }

    private function reading(Place $place, string $measuredAt, float $outdoor, float $indoor): Reading
    {
        return new Reading($place, new \DateTimeImmutable($measuredAt), $outdoor, $indoor);
    }
}
