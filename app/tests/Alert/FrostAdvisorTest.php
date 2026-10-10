<?php

declare(strict_types=1);

namespace App\Tests\Alert;

use App\Alert\FrostAdvisor;
use App\Alert\FrostLevel;
use App\Enum\DaySlot;
use App\Forecast\DayForecast;
use App\Forecast\SlotForecast;
use PHPUnit\Framework\TestCase;

final class FrostAdvisorTest extends TestCase
{
    /**
     * @param array<string, float|null> $mins minimum prévu par créneau (les absents valent 10 °C)
     */
    private static function day(string $date, array $mins = []): DayForecast
    {
        $slots = [];
        foreach (DaySlot::cases() as $slot) {
            $min = \array_key_exists($slot->value, $mins) ? $mins[$slot->value] : 10.0;
            $slots[$slot->value] = new SlotForecast($slot, $min, $min, $min, 4, 4);
        }

        return new DayForecast(new \DateTimeImmutable($date), $slots);
    }

    public function testNoAlertWhenItStaysAboveFreezing(): void
    {
        self::assertNull((new FrostAdvisor())->advise([self::day('2026-12-01'), self::day('2026-12-02', ['night' => 0.0])], new \DateTimeImmutable('2026-12-01 08:00')));
    }

    public function testFrostBelowZero(): void
    {
        $alert = (new FrostAdvisor())->advise([self::day('2026-12-01'), self::day('2026-12-02', ['night' => -0.4])], new \DateTimeImmutable('2026-12-01 08:00'));

        self::assertNotNull($alert);
        self::assertSame(FrostLevel::Frost, $alert->level);
        self::assertSame(-0.4, $alert->minimum);
        self::assertSame(DaySlot::Night, $alert->slot);
        self::assertSame('2026-12-02', $alert->date->format('Y-m-d'));
    }

    public function testSevereColdAtMinusFiveOrBelow(): void
    {
        $alert = (new FrostAdvisor())->advise([self::day('2026-12-01', ['night' => -5.0]), self::day('2026-12-02')], new \DateTimeImmutable('2026-12-01 08:00'));

        self::assertNotNull($alert);
        self::assertSame(FrostLevel::Severe, $alert->level);
    }

    public function testTheColdestSlotIsReported(): void
    {
        $alert = (new FrostAdvisor())->advise([self::day('2026-12-01', ['evening' => -1.0]), self::day('2026-12-02', ['morning' => -3.0])], new \DateTimeImmutable('2026-12-01 08:00'));

        self::assertNotNull($alert);
        self::assertSame(-3.0, $alert->minimum);
        self::assertSame(DaySlot::Morning, $alert->slot);
    }

    public function testASlotAlreadyOverIsIgnored(): void
    {
        // Le matin d'aujourd'hui a pris fin à midi : le gel de 8 h n'intéresse plus.
        $days = [self::day('2026-12-01', ['morning' => -4.0]), self::day('2026-12-02')];

        self::assertNull((new FrostAdvisor())->advise($days, new \DateTimeImmutable('2026-12-01 13:00')));
        self::assertNotNull((new FrostAdvisor())->advise($days, new \DateTimeImmutable('2026-12-01 11:59')));
    }

    public function testOnlyTodayAndTomorrowCount(): void
    {
        $days = [self::day('2026-12-01'), self::day('2026-12-02'), self::day('2026-12-03', ['night' => -10.0])];

        self::assertNull((new FrostAdvisor())->advise($days, new \DateTimeImmutable('2026-12-01 08:00')));
    }

    public function testSlotsWithoutDataAreSkipped(): void
    {
        self::assertNull((new FrostAdvisor())->advise([self::day('2026-12-01', ['night' => null, 'morning' => null, 'afternoon' => null, 'evening' => null])], new \DateTimeImmutable('2026-12-01 08:00')));
    }

    public function testNoForecastNoAlert(): void
    {
        self::assertNull((new FrostAdvisor())->advise([], new \DateTimeImmutable('2026-12-01 08:00')));
    }
}
