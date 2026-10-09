<?php

declare(strict_types=1);

namespace App\Tests\Forecast;

use App\Enum\DaySlot;
use App\Forecast\ForecastAggregator;
use App\Forecast\HourlyTemperature;
use PHPUnit\Framework\TestCase;

final class ForecastAggregatorTest extends TestCase
{
    private ForecastAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new ForecastAggregator();
    }

    public function testGroupsHoursIntoSlotsAndComputesAverageMinMax(): void
    {
        // Température = heure : matin 6..11 → moyenne 8,5 ; min 6 ; max 11.
        $days = $this->aggregator->aggregate($this->hoursEqualToTheirNumber('2026-10-09', 2), new \DateTimeImmutable('2026-10-09'), 1);

        $morning = $days[0]->forSlot(DaySlot::Morning);
        self::assertSame(8.5, $morning->average);
        self::assertSame(6.0, $morning->min);
        self::assertSame(11.0, $morning->max);
        self::assertTrue($morning->isComplete());
        self::assertSame(14.5, $days[0]->forSlot(DaySlot::Afternoon)->average);
        self::assertSame(19.5, $days[0]->forSlot(DaySlot::Evening)->average);
    }

    public function testNightRunsFromTenInTheEveningToSixTheNextMorning(): void
    {
        // 22 h et 23 h du jour D (22, 23) + 0 h à 5 h de D+1 (0..5) : moyenne 7,5.
        $days = $this->aggregator->aggregate($this->hoursEqualToTheirNumber('2026-10-09', 3), new \DateTimeImmutable('2026-10-09'), 2);

        $night = $days[0]->forSlot(DaySlot::Night);
        self::assertSame(7.5, $night->average);
        self::assertSame(0.0, $night->min);
        self::assertSame(23.0, $night->max);
        self::assertSame(8, $night->hours);
        self::assertTrue($night->isComplete());
    }

    public function testEarlyHoursOfTheFirstDayBelongToThePreviousNightAndAreNotReported(): void
    {
        $hours = [new HourlyTemperature(new \DateTimeImmutable('2026-10-09 03:00'), -50.0)];

        $days = $this->aggregator->aggregate($hours, new \DateTimeImmutable('2026-10-09'), 1);

        self::assertFalse($days[0]->forSlot(DaySlot::Night)->hasData(), 'Ces heures sont la nuit du 8 au 9.');
    }

    public function testHorizonCutsTheLastNightShort(): void
    {
        // Données jusqu'au jour 2 à 23 h seulement : la nuit du jour 2 n'a que 22 h et 23 h.
        $hours = array_filter(
            $this->hoursEqualToTheirNumber('2026-10-09', 2),
            static fn (HourlyTemperature $h): bool => true,
        );

        $days = $this->aggregator->aggregate($hours, new \DateTimeImmutable('2026-10-09'), 2);

        $lastNight = $days[1]->forSlot(DaySlot::Night);
        self::assertSame(2, $lastNight->hours);
        self::assertFalse($lastNight->isComplete());
        self::assertSame(22.5, $lastNight->average);
    }

    public function testSlotWithoutAnyHourHasNoData(): void
    {
        $days = $this->aggregator->aggregate([], new \DateTimeImmutable('2026-10-09'), 2);

        self::assertCount(2, $days);
        foreach (DaySlot::cases() as $slot) {
            self::assertFalse($days[0]->forSlot($slot)->hasData());
            self::assertNull($days[0]->forSlot($slot)->average);
        }
    }

    public function testNegativeTemperaturesAndRounding(): void
    {
        $hours = [
            new HourlyTemperature(new \DateTimeImmutable('2026-01-10 06:00'), -2.3),
            new HourlyTemperature(new \DateTimeImmutable('2026-01-10 07:00'), -2.4),
        ];

        $morning = $this->aggregator->aggregate($hours, new \DateTimeImmutable('2026-01-10'), 1)[0]->forSlot(DaySlot::Morning);

        self::assertSame(-2.4, $morning->average, '(−2,3 − 2,4) / 2 = −2,35 → −2,4 (demi vers l’éloigné de zéro)');
        self::assertSame(-2.4, $morning->min);
        self::assertSame(-2.3, $morning->max);
    }

    public function testReturnsExactlyTheRequestedDays(): void
    {
        $days = $this->aggregator->aggregate($this->hoursEqualToTheirNumber('2026-10-09', 5), new \DateTimeImmutable('2026-10-09'), 3);

        self::assertCount(3, $days);
        self::assertSame(['2026-10-09', '2026-10-10', '2026-10-11'], array_map(static fn ($d) => $d->date->format('Y-m-d'), $days));
    }

    /**
     * @return list<HourlyTemperature>
     */
    private function hoursEqualToTheirNumber(string $startDay, int $days): array
    {
        $start = new \DateTimeImmutable($startDay);
        $hours = [];
        for ($i = 0; $i < $days * 24; ++$i) {
            $at = $start->modify(\sprintf('+%d hours', $i));
            $hours[] = new HourlyTemperature($at, (float) (int) $at->format('G'));
        }

        return $hours;
    }
}
